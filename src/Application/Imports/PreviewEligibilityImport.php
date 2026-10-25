<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Domain\Inventory\BibNumber;

final readonly class PreviewEligibilityImport
{
    /** @param array<string,string>|null $headers */
    public function __construct(private ?array $headers = null, private ?int $maximumBytes = null, private ?int $maximumRows = null)
    {
    }

    /** @return list<array{row:int,status:string,bib?:string,email?:string,name?:?string,reason?:string}> */
    public function parse(string $csv): array
    {
        if (null === $this->headers || null === $this->maximumBytes || null === $this->maximumRows || $this->maximumBytes < 1 || $this->maximumRows < 1 || !isset($this->headers['bib'], $this->headers['email']) || array_diff(array_keys($this->headers), ['bib', 'email', 'name']) || count(array_unique($this->headers)) !== count($this->headers) || in_array('', $this->headers, true)) {
            throw new \LogicException('Import policy is not configured.');
        }
        if (strlen($csv) > $this->maximumBytes || !mb_check_encoding($csv, 'UTF-8')) {
            throw new \InvalidArgumentException('Import exceeds configured size or is not UTF-8.');
        }
        $stream = new \SplTempFileObject(-1);
        $stream->fwrite(str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        $stream->rewind();
        $header = $stream->fgetcsv(',', '"', '');
        if (!is_array($header) || count(array_unique($header)) !== count($header)) {
            throw new \InvalidArgumentException('Missing or duplicate import headers.');
        }
        $positions = [];
        foreach ($this->headers as $field => $name) {
            $position = array_search($name, $header, true);
            if (false === $position) {
                throw new \InvalidArgumentException('Missing import header.');
            }
            $positions[$field] = $position;
        }
        $results = [];
        $seen = [];
        while (is_array($values = $stream->fgetcsv(',', '"', ''))) {
            if ([null] === $values && $stream->eof()) {
                break;
            }
            $row = count($results) + 2;
            if (count($results) >= $this->maximumRows) {
                throw new \InvalidArgumentException('Import exceeds configured row limit.');
            }
            if (count($values) !== count($header)) {
                $results[] = ['row' => $row, 'status' => 'invalid', 'reason' => 'columns'];
                continue;
            }
            $bib = $values[$positions['bib']] ?? '';
            $email = strtolower(trim($values[$positions['email']] ?? ''));
            try {
                $bib = (new BibNumber($bib))->value;
            } catch (\InvalidArgumentException) {
                $results[] = ['row' => $row, 'status' => 'invalid', 'reason' => 'bib'];
                continue;
            }
            if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = ['row' => $row, 'status' => 'invalid', 'reason' => 'email'];
                continue;
            }
            if (isset($seen[$bib])) {
                $results[] = ['row' => $row, 'status' => 'duplicate', 'bib' => $bib];
                continue;
            }
            $seen[$bib] = true;
            $results[] = ['row' => $row, 'status' => 'valid', 'bib' => $bib, 'email' => $email, 'name' => isset($positions['name']) ? $values[$positions['name']] : null];
        }

        return $results;
    }
}
