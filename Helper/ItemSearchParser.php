<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Helper;

use MauticPlugin\CustomObjectsBundle\DTO\ItemSearchTerm;
use MauticPlugin\CustomObjectsBundle\DTO\ParsedItemSearch;

/**
 * Splits the Custom Item list search into "field:value" terms and free text. The field is an alias or a label:
 * a label with spaces goes in double quotes.
 *
 *   Curso:Matematica                   a label (or alias) of one word
 *   "Nome do Curso":Matematica         a label with spaces
 *   inicio:23/08/2026 atual:sim        two terms
 *   nome1:"Disciplina 01"              value with spaces, in double quotes
 *   momento:>="2026-08-23 10:30"        an operator may come before the quotes
 *   algebra inicio:2026-08-23          free text "algebra" and one term
 *
 * An unquoted field starts with a letter, then letters (accents too), digits or "_", then ":". So
 * "10:30" stays free text. The list controller saves the search after
 * InputHelper::clean(), which turns " < > into HTML entities, so the text is
 * decoded before it is read.
 */
class ItemSearchParser
{
    private const TERM_PATTERN = '/(?<!\S)(?:"([^"]+)"|(\p{L}[\p{L}\p{N}_]*)):((?:>=|<=|>|<|=)?)(?:"([^"]*)(?:"|$)|(\S*))/u';

    public function parse(string $search): ParsedItemSearch
    {
        $decoded = html_entity_decode($search, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!preg_match_all(self::TERM_PATTERN, $decoded, $matches, PREG_SET_ORDER)) {
            return new ParsedItemSearch($search, []);
        }

        $terms = [];
        foreach ($matches as $match) {
            // $match[1] is a quoted field, $match[2] an unquoted one, $match[3] a comparison operator kept in front of the value (quoted or not)
            $field   = '' !== $match[1] ? $match[1] : $match[2];
            $terms[] = new ItemSearchTerm($field, $match[3].(('' !== ($match[4] ?? '')) ? $match[4] : ($match[5] ?? '')));
        }

        $freeText = preg_replace(self::TERM_PATTERN, ' ', $decoded);
        $freeText = trim((string) preg_replace('/\s+/u', ' ', (string) $freeText));

        return new ParsedItemSearch($freeText, $terms);
    }
}
