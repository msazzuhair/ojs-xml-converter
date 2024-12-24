<?php

require_once __DIR__ . '../../../utils/mimes.php';

class FileProcessor {
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function validateFile(): void
    {
        if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
            throw new Exception("Error: The file '{$this->filePath}' does not exist or is not readable.");
        }
    }

    public function getFileContent(): string
    {
        $fileContent = file_get_contents($this->filePath);
        if ($fileContent === false) {
            throw new Exception("Error: Failed to read the file.");
        }
        return $fileContent;
    }
}

class DOMHandler {
    private DOMDocument $dom;

    public function __construct(string $xmlContent)
    {
        $this->dom = new DOMDocument();
        $this->dom->loadXML($xmlContent);
    }

    public function getDom(): DOMDocument
    {
        return $this->dom;
    }

    public function domToArray(DOMNode $node): array
    {
        $output = [];

        // Capture xmlns and xmlns:xsi attributes explicitly
        if ($node instanceof DOMElement) {
            if ($node->hasAttribute('xmlns')) {
                $output['@attributes']['xmlns'] = $node->getAttribute('xmlns');
            }
            if ($node->hasAttribute('xmlns:xsi')) {
                $output['@attributes']['xmlns:xsi'] = $node->getAttribute('xmlns:xsi');
            }
        }

        // Process other attributes
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attr) {
                $output['@attributes'][$attr->nodeName] = $attr->nodeValue;
            }
        }

        // Process child nodes
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->nodeValue;
                if ($text === '0' || !empty(trim($text))) {
                    $output['_value'] = $text === '0' ? 0 : trim($text);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $childName = $child->nodeName;
                $childArray = $this->domToArray($child);

                if (!isset($output[$childName])) {
                    $output[$childName] = $childArray;
                } else {
                    if (!is_array($output[$childName]) || !isset($output[$childName][0])) {
                        $output[$childName] = [$output[$childName]];
                    }
                    $output[$childName][] = $childArray;
                }
            }
        }

        return $output;
    }

    public function arrayToDom(array $data, DOMDocument $dom = null, DOMElement $parent = null, DOMElement $grandParent = null): DOMDocument
    {
        if (is_null($dom)) {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;
        }

        // Handle the root element creation
        if (is_null($parent)) {
            $rootName = array_keys($data)[0];
            $root = $dom->createElement($rootName);
            $dom->appendChild($root);
            $rootData = $data[$rootName];
            $this->arrayToDom($rootData, $dom, $root);
            return $dom;
        }

        foreach ($data as $key => $value) {
            // Skip if the value is strictly `false`
            if ($value === false) {
                continue;
            }

            if ($key === '@attributes') {
                foreach ($value as $attrName => $attrValue) {
                    if ($attrValue === false) {
                        continue;
                    }
                    $parent->setAttribute($attrName, $attrValue);
                }
            } elseif ($key === '_value') {
                if ($value === 0 || $value === '0' || !empty($value)) {
                    $parent->appendChild($dom->createTextNode($value));
                }
            } elseif (is_array($value)) {
                if (is_numeric($key)) {
                    $child = $dom->createElement($grandParent ? $parent->nodeName : 'issue');
                    $this->arrayToDom($value, $dom, $child, $parent);
                    if (!$grandParent) {
                        $parent->appendChild($child);
                    } else {
                        $grandParent->appendChild($child);
                    }
                } else {
                    $child = $dom->createElement($key);
                    $this->arrayToDom($value, $dom, $child, $parent);
                    if (!array_is_list($value)) {
                        $parent->appendChild($child);
                    }
                }
            } else {
                $child = $dom->createElement($key, htmlspecialchars($value));
                $parent->appendChild($child);
            }
        }

        return $dom;
    }
}

class DataHandler {
    private int $fileId = 0;
    private int $authorId = 500;

    public function handleSubmissionFile(array $file): array
    {
        $attributes = $file['@attributes'];
        $this->fileId++;

        if (array_is_list($file['revision'])) {
            $rev = $file['revision'][count($file['revision']) - 1];
        } else {
            $rev = $file['revision'];
        }

        return [
            '@attributes' => [
                'xmlns' => "http://pkp.sfu.ca",
                'xmlns:xsi' => "http://www.w3.org/2001/XMLSchema-instance",
                'xsi:schemaLocation' => "http://pkp.sfu.ca native.xsd",
                'id' => $attributes['id'] ?? false,
                'stage' => $attributes['stage'] ?? false,
                'file_id' => $this->fileId ?? false,
                'created_at' => $rev['@attributes']['date_uploaded'] ?? false,
                'updated_at' => $rev['@attributes']['date_uploaded'] ?? false,
                'viewable' => $rev['@attributes']['viewable'] ?? false,
                'genre' => $rev['@attributes']['genre'] ?? false,
                'uploader' => $rev['@attributes']['uploader'] ?? false,
            ],
            'name' => $rev['name'],
            'file' => [
                '@attributes' => [
                    'id' => $this->fileId,
                    'extension' => mime2ext($rev['@attributes']['filetype']),
                    'filesize' => $rev['@attributes']['filesize'],
                ],
                'embed' => $rev['embed'],
            ],
        ];
    }

    public function handleAuthor(array $author): array
    {
        $attributes = $author['@attributes'];
        $this->authorId++;

        $authorData = $author;

        unset($authorData['@attributes'], $authorData['firstname'], $authorData['middlename'], $authorData['lastname']);

        $givenName = '';
        $familyName = [];

        if (isset($author['firstname']) && !empty($author['firstname']['_value'])) {
            $givenName .= $author['firstname']['_value'];
        }

        if (isset($author['middlename']) && !empty($author['middlename']['_value'])) {
            $givenName .= ' ' . $author['middlename']['_value'];
        }

        if (isset($author['lastname']) && !empty($author['lastname']['_value'])) {
            $familyName['familyname'] = $author['lastname']['_value'];
        }

        return [
            '@attributes' => [
                ...$attributes,
                'seq' => '0',                   
                'id' => intval($this->authorId),
            ],
            'givenname' => trim($givenName),
            ...$familyName,
            ...$authorData,
        ];
    }

    public function handleArticleGalley(array $articleGalley): array
    {
        $attributes = $articleGalley['@attributes'];

        $submissionFileRefAttributes = $articleGalley['submission_file_ref']['@attributes'];

        unset($submissionFileRefAttributes['revision']);
        
        return [
            ...$articleGalley,
            'submission_file_ref' => [
                '@attributes' => $submissionFileRefAttributes,
            ],
        ];
    }

    public function handleCover(array $cover): array
    {
        $attributes = $cover['@attributes'];

        return [
            '@attributes' => $attributes,
            'cover_image' => $cover['cover_image'],
            'cover_image_alt_text' => $cover['cover_image'],
            'embed' => $cover['embed'],
        ];
    }   

    public function handleArticle(array $article): array
    {
        $attributes = $article['@attributes'];

        $hasMultipleSubmissionFiles = array_is_list($article['submission_file']);

        if ($hasMultipleSubmissionFiles) {
            $submissionFile = array_map([$this, 'handleSubmissionFile'], $article['submission_file']);
        } else {
            $submissionFile = $this->handleSubmissionFile($article['submission_file']);
        }


        $hasMultipleAuthors = array_is_list($article['authors']['author']);

        if ($hasMultipleAuthors) {
            $author = array_map([$this, 'handleAuthor'], $article['authors']['author']);
        } else {
            $author = $this->handleAuthor($article['authors']['author']);
        }

        return [
            '@attributes' => [
                'xmlns' => "http://pkp.sfu.ca",
                'xmlns:xsi' => "http://www.w3.org/2001/XMLSchema-instance",
                'locale' => $attributes['locale'],
                'date_submitted' => $attributes['date_submitted'],
                'stage' => $attributes['stage'],
            ],
            'submission_file' => $submissionFile,
            'publication' => [
                '@attributes' => [
                    'xmlns' => "http://pkp.sfu.ca",
                    'xmlns:xsi' => "http://www.w3.org/2001/XMLSchema-instance",
                    'locale' => $attributes['locale'],
                    'version' => 1,
                    'status' => 3,
                    'seq' => $attributes['seq'],
                    'date_published' => $attributes['date_published'],
                    'section_ref' => $attributes['section_ref'],
                    'access_status' => 0,
                ],
                'title' => $article['title'],
                'abstract' => $article['abstract'],
                'copyrightHolder' => $article['copyrightHolder'] ?? false,
                'copyrightYear' => $article['copyrightYear'] ?? false,
                'keywords' =>  $article['keywords'] ?? false,
                'authors' => [
                    '@attributes' => $article['authors']['@attributes'],
                    'author' => $author,
                ],
                'article_galley' => $this->handleArticleGalley($article['article_galley']),
            ],
        ];
    }

    public function issueCoversToCovers(array $issue): array
    {
        $temp = $issue;
        unset($temp['issue_covers']);

        $hasMultipleCovers = array_is_list($issue['issue_covers']['cover']);

        if ($hasMultipleCovers) {
            $cover = array_map([$this, 'handleCover'], $issue['issue_covers']['cover']);
        } else {
            $cover = $this->handleCover($issue['issue_covers']['cover']);
        }

        return [
            'id' => $issue['id'] ?? false,
            'description' => $issue['description'] ?? false,
            'issue_identification' => $issue['issue_identification'],
            'date_published' => $issue['date_published'] ?? false,
            'date_notified' => $issue['date_notified'] ?? false,
            'last_modified' => $issue['last_modified'] ?? false,
            'open_access_date' => $issue['open_access_date'] ?? false,
            'sections' => $issue['sections'] ?? false,
            // 'covers' => $issue['issue_covers'],
            'covers' => [
                'cover' => $cover,
            ],
            'issue_galleys' => $issue['issue_galleys'] ?? false,
            'articles' => $issue['articles'] ?? false,
        ];
    }

    public function handle(array $issue): array
    {
        $issue = $this->issueCoversToCovers($issue);
        return [
            ...$issue,
            "articles" => [
                '@attributes' => $issue['articles']['@attributes'],
                'article' => array_map([$this, 'handleArticle'], $issue['articles']['article']),
            ]
        ];
    }

    public function handleIssues(array $issues): array
    {
        $processedIssues = [];
        foreach ($issues['issue'] as $issue) {
            $processedIssues[] = $this->handle($issue);
        }

        // Write processed issues to a file for debugging
        // file_put_contents('processed_issues.json', json_encode($processedIssues, JSON_PRETTY_PRINT));
        // die();

        return $processedIssues;
    }
}

class CLIApp {
    private FileProcessor $fileProcessor;
    private DOMHandler $domHandler;
    private DataHandler $dataHandler;
    private string $outputFilePath;

    public function __construct(string $filePath, string $outputFilePath)
    {
        $this->fileProcessor = new FileProcessor($filePath);
        $this->dataHandler = new DataHandler();
        $this->outputFilePath = $outputFilePath;
    }

    public function run(): void
    {
        try {
            $this->fileProcessor->validateFile();
            $fileContent = $this->fileProcessor->getFileContent();

            $this->domHandler = new DOMHandler($fileContent);
            $rootElement = $this->domHandler->getDom()->documentElement;
            $array = $this->domHandler->domToArray($rootElement);

            if ($rootElement->nodeName === 'issue') {
                // Handle a single issue
                $data = [
                    'issue' => $this->dataHandler->handle($array)
                ];
            } elseif ($rootElement->nodeName === 'issues') {
                // Handle multiple issues
                $data = [
                    'issues' => [
                        '@attributes' => [
                            'xmlns' => "http://pkp.sfu.ca",
                            'xmlns:xsi' => "http://www.w3.org/2001/XMLSchema-instance",
                            'xsi:schemaLocation' => "http://pkp.sfu.ca native.xsd",
                        ],
                        'issue' => $this->dataHandler->handleIssues($array)
                    ]
                ];
            } else {
                throw new Exception("Unsupported root element: {$rootElement->nodeName}");
            }

            $xmlDom = $this->domHandler->arrayToDom($data);
            $xmlDom->save($this->outputFilePath);

            echo "XML saved successfully to '{$this->outputFilePath}'.\n";
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }
}


// Check if the script is being run from the CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Check if a file path and output file path arguments are provided
if ($argc !== 3) {
    echo "Usage: php script.php <input_file_path> <output_file_path>\n";
    exit(1);
}

// Instantiate and run the CLI application
$inputFilePath = $argv[1];
$outputFilePath = $argv[2];

$app = new CLIApp($inputFilePath, $outputFilePath);
$app->run();

exit(0);
