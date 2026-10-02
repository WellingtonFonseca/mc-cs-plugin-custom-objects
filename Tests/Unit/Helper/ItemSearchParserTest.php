<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Helper;

use MauticPlugin\CustomObjectsBundle\Helper\ItemSearchParser;
use PHPUnit\Framework\TestCase;

class ItemSearchParserTest extends TestCase
{
    private ItemSearchParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ItemSearchParser();
    }

    public function testSearchWithoutColonIsLeftUntouched(): void
    {
        $parsed = $this->parser->parse('Disciplina &#34;01&#34;  x');

        $this->assertFalse($parsed->hasTerms());
        $this->assertSame('Disciplina &#34;01&#34;  x', $parsed->getFreeText());
    }

    public function testSingleTerm(): void
    {
        $parsed = $this->parser->parse('inicio:23/08/2026');

        $this->assertTrue($parsed->hasTerms());
        $this->assertSame('', $parsed->getFreeText());
        $this->assertCount(1, $parsed->getTerms());
        $this->assertSame('inicio', $parsed->getTerms()[0]->alias);
        $this->assertSame('23/08/2026', $parsed->getTerms()[0]->value);
    }

    public function testSeveralTermsAreKeptInOrder(): void
    {
        $terms = $this->parser->parse('inicio:23/08/2026 atual:sim')->getTerms();

        $this->assertSame(['inicio', 'atual'], array_map(fn ($t) => $t->alias, $terms));
        $this->assertSame(['23/08/2026', 'sim'], array_map(fn ($t) => $t->value, $terms));
    }

    public function testQuotedValueKeepsSpaces(): void
    {
        $terms = $this->parser->parse('nome1:"Disciplina 01" atual:sim')->getTerms();

        $this->assertSame('Disciplina 01', $terms[0]->value);
        $this->assertSame('atual', $terms[1]->alias);
    }

    public function testQuotedValueAsSavedByInputHelper(): void
    {
        // The list controller runs InputHelper::clean(), which turns " into &#34;.
        $terms = $this->parser->parse('nome1:&#34;Disciplina 01&#34;')->getTerms();

        $this->assertSame('Disciplina 01', $terms[0]->value);
    }

    public function testComparisonPrefixAsSavedByInputHelper(): void
    {
        $terms = $this->parser->parse('inicio:&#60;2026-09-01 progresso:&#62;=0.5')->getTerms();

        $this->assertSame('<2026-09-01', $terms[0]->value);
        $this->assertSame('>=0.5', $terms[1]->value);
    }

    public function testUnclosedQuoteTakesTheRestOfTheText(): void
    {
        $terms = $this->parser->parse('nome1:"Disciplina 01')->getTerms();

        $this->assertSame('Disciplina 01', $terms[0]->value);
    }

    public function testRemainingWordsBecomeFreeText(): void
    {
        $parsed = $this->parser->parse('algebra inicio:2026-08-23 linear');

        $this->assertSame('algebra linear', $parsed->getFreeText());
        $this->assertCount(1, $parsed->getTerms());
    }

    public function testEmptyValueIsKeptAsEmptyString(): void
    {
        $terms = $this->parser->parse('inicio:')->getTerms();

        $this->assertSame('inicio', $terms[0]->alias);
        $this->assertSame('', $terms[0]->value);
    }

    public function testTimeLikeTextIsNotATerm(): void
    {
        $parsed = $this->parser->parse('aula 10:30');

        $this->assertFalse($parsed->hasTerms());
        $this->assertSame('aula 10:30', $parsed->getFreeText());
    }

    public function testColonInsideTheValueStaysInTheValue(): void
    {
        $terms = $this->parser->parse('momento:"2026-08-23 10:30"')->getTerms();

        $this->assertSame('momento', $terms[0]->alias);
        $this->assertSame('2026-08-23 10:30', $terms[0]->value);
    }

    public function testQuotedValueAfterAComparisonOperator(): void
    {
        $terms = $this->parser->parse('momento:>="2026-08-23 10:30" fim:<"01/09/2026" x:&#62;&#34;a b&#34;')->getTerms();

        $this->assertSame('>=2026-08-23 10:30', $terms[0]->value);
        $this->assertSame('<01/09/2026', $terms[1]->value);
        $this->assertSame('>a b', $terms[2]->value);
        $this->assertCount(3, $terms);
    }
}
