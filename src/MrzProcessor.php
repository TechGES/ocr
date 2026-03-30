<?php

namespace Ges\Ocr;

use Ges\Ocr\Support\DocumentProcessingValues;

class MrzProcessor
{
    /**
     * @param  array<int, string>  $candidates
     */
    public function best(string $documentType, array $candidates): string
    {
        $evaluated = [];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCandidate($documentType, $candidate);

            if ($normalized === '') {
                continue;
            }

            $evaluated[] = [
                'value' => $normalized,
                'score' => $this->scoreCandidate($documentType, $normalized),
            ];
        }

        if ($evaluated === []) {
            return '';
        }

        if (in_array($documentType, [
            DocumentProcessingValues::BUSINESS_TYPE_IDENTITY_CARD,
            DocumentProcessingValues::BUSINESS_TYPE_RESIDENCE_PERMIT,
        ], true)) {
            $multiline = array_values(array_filter($evaluated, static fn (array $entry): bool => str_contains($entry['value'], "\n")));

            if ($multiline !== []) {
                usort($multiline, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

                return $multiline[0]['value'];
            }
        }

        usort($evaluated, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $evaluated[0]['value'];
    }

    private function normalizeCandidate(string $documentType, string $candidate): string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return '';
        }

        $candidate = mb_strtoupper($candidate);
        $candidate = preg_replace('/[ \t\r\f\v]+/u', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/[^A-Z0-9<\n]/u', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\n+/u', "\n", $candidate) ?? $candidate;
        $candidate = trim($candidate);

        if ($candidate === '') {
            return '';
        }

        if (in_array($documentType, [
            DocumentProcessingValues::BUSINESS_TYPE_IDENTITY_CARD,
            DocumentProcessingValues::BUSINESS_TYPE_RESIDENCE_PERMIT,
        ], true)) {
            $candidate = $this->normalizeTd1Candidate($candidate);
        }

        return trim($candidate);
    }

    private function normalizeTd1Candidate(string $candidate): string
    {
        $lines = preg_split('/\R+/u', $candidate) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if (count($lines) >= 2) {
            $structured = $this->extractTd1Lines($lines);

            if ($structured !== []) {
                return implode("\n", $structured);
            }

            return implode("\n", $lines);
        }

        $compact = str_replace("\n", '', $candidate);

        if (preg_match('/^I([DR])<(?=[A-Z]{3})/u', $compact) === 1) {
            $compact = preg_replace('/^I([DR])<(?=[A-Z]{3})/u', 'I$1', $compact) ?? $compact;
        }

        if (
            preg_match(
                '/^([A-Z0-9<]{30})([A-Z0-9<]{30})([A-Z<]{24,30})$/u',
                $compact,
                $matches
            ) === 1
        ) {
            return $matches[1]."\n".$matches[2]."\n".$matches[3];
        }

        return $candidate;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function extractTd1Lines(array $lines): array
    {
        $line1 = '';
        $line2 = '';
        $line3 = '';

        foreach ($lines as $line) {
            if (
                $line1 === ''
                && preg_match('/^I[DR][A-Z]{3}[A-Z0-9<]{20,}$/u', $line) === 1
            ) {
                $line1 = substr($line, 0, min(strlen($line), 30));

                continue;
            }

            if (
                $line2 === ''
                && preg_match('/^\d{6}\d[MFX<]\d{6}\d[A-Z<]{3}/u', $line) === 1
            ) {
                $line2 = substr($line, 0, min(strlen($line), 30));

                continue;
            }

            if (
                $line3 === ''
                && preg_match('/^[A-Z<]+<<[A-Z<]+$/u', $line) === 1
            ) {
                $line3 = substr($line, 0, min(strlen($line), 30));
            }
        }

        return array_values(array_filter([$line1, $line2, $line3], static fn (string $line): bool => $line !== ''));
    }

    private function scoreCandidate(string $documentType, string $candidate): int
    {
        $score = 0;
        $lines = preg_split('/\R+/u', $candidate) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        $compact = str_replace("\n", '', $candidate);

        foreach ($lines as $line) {
            if (preg_match('/^[A-Z0-9<]+$/u', $line) === 1) {
                $score += 5;
            }

            if (strlen($line) > 44) {
                $score -= 80;
            }

            if (str_contains($line, '<<')) {
                $score += 20;
            }

            if (preg_match('/\d{6}\d[MFX<]\d{6}\d[A-Z<]{3}/u', $line) === 1) {
                $score += 30;
            }
        }

        if (count($lines) >= 2) {
            $score += 220;
        }

        if (count($lines) === 3) {
            $score += 120;
        }

        if ($compact !== '' && preg_match('/\d{6}\d[MFX<]\d{6}\d[A-Z<]{3}/u', $compact) === 1) {
            $score += 20;
        }

        if (
            in_array($documentType, [
                DocumentProcessingValues::BUSINESS_TYPE_IDENTITY_CARD,
                DocumentProcessingValues::BUSINESS_TYPE_RESIDENCE_PERMIT,
            ], true)
        ) {
            if (count($lines) === 3 && strlen($lines[0]) === 30 && strlen($lines[1]) === 30 && strlen($lines[2]) <= 30) {
                $score += 120;
            }

            if ($compact !== '' && preg_match('/^I[DR][A-Z]{3}/u', $compact) === 1) {
                $score += 25;
            }

            if (count($lines) > 3) {
                $score -= 100;
            }
        }

        if (strlen($compact) > 120 && count($lines) <= 1) {
            $score -= 100;
        }

        return $score;
    }
}
