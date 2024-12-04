<?php

declare(strict_types=1);

namespace NTVCourses\Converter;

use NTVCourses\Logs\ErrorEntry;
use NTVCourses\Logs\LogEntry;

final class XmlToCsvConverter
{
    private readonly \SimpleXMLElement $xml;
    private array $headers = [];
    private array $data = [];

    public function __construct(
        private readonly string $xmlPath,
        private readonly string $csvPath,
        private readonly string $rootElement = '',
        private readonly array $options = []
    ) {
        $this->validatePaths();
        $this->loadXml();
    }

    public function convert(): bool
    {
        try {
            $this->extractData();
            $this->writeCsv();
            
            (new LogEntry(
                method: 'CONVERT',
                url: $this->xmlPath,
                data: ['destination' => $this->csvPath],
                response: 'Conversion successful',
                type: 'converter',
                metadata: [
                    'records_processed' => count($this->data),
                    'headers' => $this->headers
                ]
            ))->write();
            
            return true;
        } catch (\Throwable $e) {
            (new ErrorEntry(
                message: 'XML to CSV conversion failed',
                type: 'CONVERTER',
                context: [
                    'xml_path' => $this->xmlPath,
                    'csv_path' => $this->csvPath,
                    'root_element' => $this->rootElement
                ],
                exception: $e
            ))->write();
            
            return false;
        }
    }

    private function validatePaths(): void
    {
        if (!file_exists($this->xmlPath)) {
            throw new \RuntimeException(
                sprintf('XML file not found: %s', $this->xmlPath)
            );
        }

        $csvDir = dirname($this->csvPath);
        if (!is_dir($csvDir) && !mkdir($csvDir, 0755, true)) {
            throw new \RuntimeException(
                sprintf('Cannot create directory: %s', $csvDir)
            );
        }
    }

    private function loadXml(): void
    {
        $xmlContent = file_get_contents($this->xmlPath);
        if ($xmlContent === false) {
            throw new \RuntimeException(
                sprintf('Failed to read XML file: %s', $this->xmlPath)
            );
        }

        libxml_use_internal_errors(true);
        $this->xml = new \SimpleXMLElement($xmlContent);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($errors) {
            throw new \RuntimeException(
                sprintf('XML parsing errors: %s', $this->formatXmlErrors($errors))
            );
        }
    }

    private function extractData(): void
    {
        $elements = $this->rootElement
            ? $this->xml->xpath("//{$this->rootElement}")
            : [$this->xml];

        foreach ($elements as $element) {
            $this->processElement($element);
        }

        $this->headers = array_unique($this->headers);
    }

    private function processElement(\SimpleXMLElement $element): void
    {
        $row = [];
        $this->extractElementData($element, $row);
        
        if (!empty($row)) {
            $this->data[] = $row;
            $this->headers = array_unique([
                ...$this->headers,
                ...array_keys($row)
            ]);
        }
    }

    private function extractElementData(
        \SimpleXMLElement $element,
        array &$data,
        string $prefix = ''
    ): void {
        foreach ($element->attributes() as $key => $value) {
            $fullKey = $prefix ? "{$prefix}_{$key}" : (string)$key;
            $data[$fullKey] = (string)$value;
        }

        if ($element->count() === 0) {
            $key = $prefix ?: 'value';
            $data[$key] = (string)$element;
            return;
        }

        foreach ($element->children() as $childKey => $child) {
            $fullKey = $prefix ? "{$prefix}_{$childKey}" : $childKey;
            $this->extractElementData($child, $data, (string)$fullKey);
        }
    }

    private function writeCsv(): void
    {
        $handle = fopen($this->csvPath, 'w');
        if ($handle === false) {
            throw new \RuntimeException(
                sprintf('Failed to open CSV file for writing: %s', $this->csvPath)
            );
        }

        try {
            // Write UTF-8 BOM
            fwrite($handle, "\xEF\xBB\xBF");

            // Write headers
            fputcsv($handle, $this->headers);

            // Write data
            foreach ($this->data as $row) {
                $csvRow = array_map(
                    fn($header) => $row[$header] ?? '',
                    $this->headers
                );
                fputcsv($handle, $csvRow);
            }
        } finally {
            fclose($handle);
        }
    }

    private function formatXmlErrors(array $errors): string
    {
        return implode("\n", array_map(
            fn(\LibXMLError $error) => sprintf(
                'Line %d: %s',
                $error->line,
                trim($error->message)
            ),
            $errors
        ));
    }
}
