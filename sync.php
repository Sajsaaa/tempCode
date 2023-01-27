<?php

namespace Grav\Plugin\Console;

use Grav\Common\Plugin;
use Grav\Console\ConsoleCommand;
use Grav\Framework\File\Formatter\MarkdownFormatter;
use Lokalise\LokaliseApiClient;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Finder\Finder;

class SyncCommand extends ConsoleCommand
{
    const PROJECT_ID_ARGUMENT = "project-id";
    const API_TOKEN_ARGUMENT = "api-token";
    const SOURCE_LOCALE_ARGUMENT = "source-locale";
    const DESTINATION_LOCALE_ARGUMENT = "destination-locale";


    const DETAILS_OPTION = 'details';
    const DRY_RUN_OPTION = "dry-run";
    const FORCE_OPTION = "force";

    protected function configure()
    {
        $this->setName("sync")
            ->setDescription("Sync translations with Lokalise")
            ->addOption(
                self::DRY_RUN_OPTION,
                null,
                InputOption::VALUE_NONE,
                "Execute command without changes"
            )
            ->addOption(
                self::FORCE_OPTION,
                null,
                InputOption::VALUE_NONE,
                'Delete non existed keys from lokalise'
            )
            ->addOption(
                self::DETAILS_OPTION,
                null,
                InputOption::VALUE_NONE,
                "More output for processing"
            )
            ->addArgument(
                self::PROJECT_ID_ARGUMENT,
                InputArgument::REQUIRED,
                "Lokalise project id"
            )
            ->addArgument(
                self::API_TOKEN_ARGUMENT,
                InputArgument::REQUIRED
            )
            ->addArgument(
                self::SOURCE_LOCALE_ARGUMENT,
                InputArgument::REQUIRED,
                "Base locale for translations"
            )
            ->addArgument(
                self::DESTINATION_LOCALE_ARGUMENT,
                InputArgument::REQUIRED,
                "Comma separated list of destination locale"
            );
    }

    protected function serve()
    {
        $projectId = $this->input->getArgument(self::PROJECT_ID_ARGUMENT);
        $apiToken = $this->input->getArgument(self::API_TOKEN_ARGUMENT);
        $sourceLocale = $this->input->getArgument(self::SOURCE_LOCALE_ARGUMENT);
        $destinationLocales = array_map(function (string $v): string {
            return trim($v);
        }, explode(',', $this->input->getArgument(self::DESTINATION_LOCALE_ARGUMENT)));

        $forceDeleteKeysOption = $this->input->getOption(self::FORCE_OPTION);
        $dryRunOption = $this->input->getOption(self::DRY_RUN_OPTION);
        $moreDetailsOption = $this->input->getOption(self::DETAILS_OPTION);

        // get keys from pages
        $pagesDirectory = realpath(implode(DIRECTORY_SEPARATOR, [getcwd(), "user", "pages"]));

        $this->output->writeln("Processing pages ...");
        $finder = new Finder();
        $finder->files()->name("*." . $sourceLocale . ".md")->in($pagesDirectory);
        $pages = [];

        $markdownFormatter = new MarkdownFormatter();

        $skipPages = [
            'bug-bounty-policy',
            'cookie-policy',
            'referral-program-policy',
            'terms-and-conditions',
            'privacy-policy'
        ];

        $skipNonSortedPages = [
            'qi-agreement',
            'investment',
            'ctmdeck',
            'qi-review'
        ];

        $skipHeaderKeys = [
            'page_title',
            'og_description',
            'og_title',
            'twitter_title'
        ];

        foreach ($finder as $file) {
            if ($moreDetailsOption) {
                $this->output->writeln(sprintf("Processing file: %s", $file->getRelativePathname()));
            }
            $keyNameParts = explode(DIRECTORY_SEPARATOR, $file->getRelativePathname());
            $skip = false;
            foreach ($skipPages as $skipPage) {
                $pattern = sprintf("/^\d+\.%s$/", $skipPage);
                if (preg_match($pattern, $keyNameParts[0])) {
                    if (count($keyNameParts) > 2) {
                        $skip = true;
                        break;
                    }
                }
            }
            foreach ($skipNonSortedPages as $skipNonSortedPage) {
                if ($skipNonSortedPage === $keyNameParts[0]) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                if ($moreDetailsOption) {
                    $this->output->writeln(sprintf("Skip key: %s", implode('|', $keyNameParts)));
                }
                continue;
            }

            // skip blog
            if (preg_match("/^\d+\.blog$/",$keyNameParts[0])) {
                if (count($keyNameParts) > 2 && !preg_match('/^\d+.[a-z\-_]+/', $keyNameParts[1])) {
                    if ($moreDetailsOption) {
                        $this->output->writeln(
                            sprintf("Skip page: %s", implode('|', $keyNameParts))
                        );
                    }
                    // skip non-numeric
                    continue;
                }
            }

            // skip carriers
            if (preg_match("/^\d+\.careers$/", $keyNameParts[0])) {
                if (count($keyNameParts) > 2 && !preg_match('/^\d+.[a-z\-_]+/', $keyNameParts[1])) {
                    if ($moreDetailsOption) {
                        $this->output->writeln(
                            sprintf("Skip page: %s", implode('|', $keyNameParts))
                        );
                    }
                    // skip non-numeric
                    continue;
                }
                if (preg_match('/\d+\._careers-main$/', $keyNameParts[1])) {
                    // skip careers reward page
                    continue;
                }
            }

            array_unshift($keyNameParts, "pages");

            $key = implode("|", $keyNameParts);
            $fileContent = file_get_contents($file->getPathname());
            if ($fileContent === '') {
                continue;
            }
            $content = explode("---", $fileContent);
            $d = $markdownFormatter->decode($fileContent);

            foreach (array_keys($d['header']) as $headerKey) {
                if (in_array($headerKey, $skipHeaderKeys)) {
                    if ($moreDetailsOption) {
                        $this->output->writeln(sprintf("Skip key: %s", $headerKey));
                    }
                    continue;
                }
                $value = $d['header'][$headerKey];
                $subKey = null;
                if (preg_match('/\w*title\w*$/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                if (preg_match('/^title_[a-zA-Z\d]+/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                if (preg_match('/^text\d*$/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                if (preg_match('/^text_[a-zA-Z\d]+/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                if (preg_match('/[a-zA-Z]+_text$/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                if (in_array($headerKey, ['linkText', 'link_name', 'button_name', 'btn_name'])) {
                    $subKey = $headerKey;
                }
                if (preg_match('/[A-Za-z_]*description.*$/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                if (preg_match('/[a-zA-Z_]*placeholder$/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }
                // in template: some_element_label
                if (preg_match('/[a-zA-Z]+_label$/', $headerKey, $matches)) {
                    $subKey = $headerKey;
                }

                if ($subKey != null) {
                    if (!preg_match('/^[a-z\d]+-[-a-z\d]+$/', $value)) {
                        $pages[$key . '|' . $subKey] = trim($value);
                    }
                }
                if (in_array($headerKey, ['list', 'company', 'resources', 'flow', 'licenses', 'focus_list', 'footer_list', 'hero_list'])) {
                    $list = $d['header'][$headerKey];
                    for ($i = 0; $i < count($list); $i++) {
                        foreach (array_keys($list[$i]) as $listKey) {
                            if (in_array($listKey, ['description', 'position', 'title', 'btn_name', 'name', 'yld', 'text'])) {
                                $pages[$key . '|' . $headerKey . '|' . $i . '|' . $listKey] = trim($list[$i][$listKey]);
                            } elseif (in_array($listKey, ['left_list', 'right_list'])) {
                                // processing left and right sub lists
                               foreach ($list[$i][$listKey] as $subKey => $subListValue) {
                                   $pages[$key . '|' . $headerKey . '|' . $i . '|' . $listKey . '|' . $subKey] = trim($list[$i][$listKey][$subKey]);
                               }
                            }
                        }
                    }
                }

                /*
                 * Processing assoc list
                 */
                if (preg_match('/.+_assoc_list$/', $headerKey, $matches)) {
                    $list = $d['header'][$headerKey];
                    foreach($list as $itemKey => $value) {
                        $pages[$key . '|' . $headerKey . '|' . $itemKey] = $value;
                    }
                }
            }

            if (trim($content[2]) === '') {
                continue;
            }
            $pages[$key] = trim($content[2]);
        }

        $this->output->writeln(sprintf("Generated %s keys from CMS pages", count($pages)));

        $this->output->writeln("Start syncing");
        $client = new LokaliseApiClient($apiToken);
        $response = $client->keys->fetchAll($projectId, [
            'include_translations' => 1,
        ]);

        $lokaliseKeys = $response->getContent()['keys'];
        $lokaliseLanguages = [
            'zh_TW' => 'zh-tw',
            'zh_CN' => 'zh-cn'
        ];
        $keys = [];
        $this->output->writeln(sprintf("Received %s keys from Localise", count($lokaliseKeys)));
        $updatedOrCreatedTranslations = 0;
        $outdatedOriginalContents = 0;
        $nonExistedKeys = 0;
        foreach ($response->getContent()['keys'] as $keyData) {
            $keyName = $keyData['key_name']['web'];
            $keys[] = $keyName;
            $translations = $keyData['translations'];

            if (in_array($keyName, array_keys($pages))) {
                if ($moreDetailsOption) {
                    $this->output->writeln("Processing " . $keyName);
                }

                $sourceContentWasChanged = false;
                foreach ($translations as $translation) {
                    if ($translation['language_iso'] === $sourceLocale) {
                        if (strcmp($pages[$keyName], $translation['translation']) !== 0) {
                            $this->output->warning(sprintf("Original content for `%s` was changed", $keyName));
                            // update translation with new content
                            $response = $client->translations->update(
                                $projectId,
                                $translation['translation_id'], [
                                    'translation' => $pages[$keyName],
                                    'is_reviewed' => false
                                ]
                            );
                            $outdatedOriginalContents++;
                            $sourceContentWasChanged = true;
                            break;
                        }
                    }
                }
                if ($sourceContentWasChanged) continue;

                foreach ($translations as $translation) {
                    $translationLocale = $translation['language_iso'];
                    if (array_key_exists($translationLocale, $lokaliseLanguages)) {
                        $translationLocale = $lokaliseLanguages[$translationLocale]; // replace lokalise locale with grav locale
                    }
                    $translationContent = $translation['translation'];
                    if (trim($translationContent) === '') {
                        // skip empty content
                        continue;
                    }

                    if (in_array($translationLocale, $destinationLocales)) {
                        $keyNameParts = explode('|', $keyName);
                        $filePathParts = [];
                        $fileHeaderParts = [];
                        $filePathRead = false;
                        foreach ($keyNameParts as $keyNamePart) {
                            if (!$filePathRead) {
                                if (preg_match('/^.*\.md$/', $keyNamePart, $matches)) {
                                    $filePathRead = true;
                                }
                                $filePathParts[] = $keyNamePart;
                            } else {
                                $fileHeaderParts[] = $keyNamePart;
                            }
                        }

                        $itemName = array_pop($filePathParts);

                        $sourceFilePath = realpath(
                            implode(
                                DIRECTORY_SEPARATOR,
                                array_merge(
                                    [
                                        getcwd(),
                                        "user"
                                    ],
                                    $filePathParts,
                                    [
                                        $itemName
                                    ]
                                )
                            )
                        );
                        $destinationFilePath = implode(
                            DIRECTORY_SEPARATOR,
                            array_merge(
                                [
                                    getcwd(),
                                    "user",
                                ],
                                $filePathParts,
                                [
                                    str_replace('.' . $sourceLocale . '.', '.' . $translationLocale . '.', $itemName)
                                ]
                            )
                        );

                        if ($dryRunOption) {
                            $originalContent = file_get_contents($sourceFilePath);
                        } else {
                            if (!file_exists($destinationFilePath)) {
                                file_put_contents($destinationFilePath, file_get_contents($sourceFilePath));
                            }
                            $originalContent = file_get_contents($destinationFilePath);
                        }

                        $pageData = $markdownFormatter->decode($originalContent);
                        if (count($fileHeaderParts) === 0) {
                            $pageData['markdown'] = $translationContent;
                        } else {
                            if (count($fileHeaderParts) == 1) {
                                $pageData['header'][$fileHeaderParts[0]] = $translationContent;
                            } else {
                                // lists
                                if (count($fileHeaderParts) == 4) {
                                    $pageData['header'][$fileHeaderParts[0]][(int)$fileHeaderParts[1]][$fileHeaderParts[2]][(int)$fileHeaderParts[3]] = $translationContent;
                                } elseif(count($fileHeaderParts) == 3) {
                                    $pageData['header'][$fileHeaderParts[0]][(int)$fileHeaderParts[1]][$fileHeaderParts[2]] = $translationContent;
                                } else {
                                    // assoc list
                                    $pageData['header'][$fileHeaderParts[0]][$fileHeaderParts[1]] = $translationContent;
                                }
                            }
                        }

                        if (!$dryRunOption) {
                            file_put_contents($destinationFilePath, $markdownFormatter->encode($pageData));
                        }

                        $updatedOrCreatedTranslations++;
                    }
                }
            } else {
                $this->output->warning(sprintf("Key `%s` does not exist in CMS. Removing...", $keyName));
                if (!$dryRunOption && $forceDeleteKeysOption) {
                    $client->keys->delete($projectId, $keyData['key_id']);
                }
                $nonExistedKeys++;
            }
        }

        if ($outdatedOriginalContents > 0) {
            $this->output->writeln(sprintf("Updated outdated original content for %s keys", $outdatedOriginalContents));
        } else {
            $this->output->writeln("No changed original content is founded");
        }

        if ($nonExistedKeys > 0) {
            $this->output->writeln(sprintf("Founded and removed %s non existed keys", $nonExistedKeys));
        } else {
            $this->output->writeln("Non-existed key not found");
        }

        if ($updatedOrCreatedTranslations > 0) {
            $this->output->writeln(sprintf("Updated or created %s translations", $updatedOrCreatedTranslations));
        } else {
            $this->output->writeln("No new translations founded");
        }

        $this->output->writeln("Processing new keys...");
        $newKeys = array_diff(array_keys($pages), $keys);
        if (count($newKeys) > 0) {
            $this->output->writeln(sprintf("Founded %s new keys", count($newKeys)));
            $requestKeys = [];
            foreach ($newKeys as $newKey) {
                $requestKeys[] = [
                    'key_name' => $newKey,
                    'platforms' => [
                        'web'
                    ],
                    'translations' => [
                        [
                            'language_iso' => $sourceLocale,
                            'translation' => $pages[$newKey]
                        ]
                    ]
                ];
            }
            if (!$dryRunOption) {
                $response = $client->keys->create($projectId, [
                    'keys' => $requestKeys
                ]);
                $this->output->writeln(sprintf("Created %s new keys", count($response->getContent()['keys'])));
            }
        } else {
            $this->output->writeln("No new keys was created");
        }
    }
}
