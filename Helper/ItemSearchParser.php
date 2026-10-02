<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use MauticPlugin\CustomObjectsBundle\DTO\ItemSearchTerm;
use MauticPlugin\CustomObjectsBundle\DTO\ParsedItemSearch;

/**
 * Splits the Custom Item list search into "alias:value" terms and free text.
 *
 *   inicio:23/08/2026 atual:sim        two terms
 *   nome1:"Disciplina 01"              value with spaces, in double quotes
 *   momento:>="2026-08-23 10:30"        an operator may come before the quotes
 *   algebra inicio:2026-08-23          free text "algebra" and one term
 *
 * A term starts with a letter, then letters, digits or "_", then ":". So
 * "10:30" stays free text. The list controller saves the search after
 * InputHelper::clean(), which turns " < > into HTML entities, so the text is
 * decoded before it is read.
 */
class ItemSearchParser
{
    private const TERM_PATTERN = '/(?<!\S)([A-Za-z][A-Za-z0-9_]*):((?:>=|<=|>|<|=)?)(?:"([^"]*)(?:"|$)|(\S*))/u';

    public function parse(string $search): ParsedItemSearch
    {
        $decoded = html_entity_decode($search, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!preg_match_all(self::TERM_PATTERN, $decoded, $matches, PREG_SET_ORDER)) {
            return new ParsedItemSearch($search, []);
        }

        $terms = [];
        foreach ($matches as $match) {
            // $match[2] is a comparison operator, kept in front of the value (quoted or not)
            $terms[] = new ItemSearchTerm($match[1], $match[2].(('' !== ($match[3] ?? '')) ? $match[3] : ($match[4] ?? '')));
        }

        $freeText = preg_replace(self::TERM_PATTERN, ' ', $decoded);
        $freeText = trim((string) preg_replace('/\s+/u', ' ', (string) $freeText));

        return new ParsedItemSearch($freeText, $terms);
    }
}
