<?php

require_once __DIR__ . '/phpunit-stubs.php';

use PHPUnit\Framework\TestCase;

final class AnnotationCoreTest extends TestCase {
    public function testDefaultDecisionChoicesAreReturnedWhenSchemaIsMissing() {
        $choices = parseDecisionChoices(null);

        $this->assertSame(
            [
                ['label' => 'Yes', 'value' => 'yes', 'shortcut' => '1'],
                ['label' => 'No', 'value' => 'no', 'shortcut' => '2'],
            ],
            $choices
        );
    }

    public function testDecisionChoicesAreNormalizedFromJsonSchema() {
        $choices = parseDecisionChoices(json_encode([
            'choices' => [
                ['label' => 'Very Positive', 'shortcut' => '1'],
                ['label' => 'Needs Review', 'value' => 'needs_review', 'shortcut' => '2'],
                ['label' => 'Very Positive', 'shortcut' => '3'],
            ],
        ]));

        $this->assertSame(
            [
                ['label' => 'Very Positive', 'value' => 'very_positive', 'shortcut' => '1'],
                ['label' => 'Needs Review', 'value' => 'needs_review', 'shortcut' => '2'],
            ],
            $choices
        );
    }

    public function testDecisionChoiceValidationUsesChoiceValues() {
        $choices = normalizeDecisionChoices([
            ['label' => 'Accept', 'value' => 'accept', 'shortcut' => '1'],
            ['label' => 'Reject', 'value' => 'reject', 'shortcut' => '2'],
        ]);

        $this->assertTrue(isValidDecisionChoice('accept', $choices));
        $this->assertFalse(isValidDecisionChoice('maybe', $choices));
        $this->assertSame(['label' => 'Reject', 'value' => 'reject', 'shortcut' => '2'], findDecisionChoice('reject', $choices));
    }

    public function testDecisionChoicesCanBeBuiltFromTextareaStyleInput() {
        $choices = buildDecisionChoicesFromText("Accept | 1 | accept\nReject | 2 | reject\nNeeds Review");

        $this->assertSame(
            [
                ['label' => 'Accept', 'value' => 'accept', 'shortcut' => '1'],
                ['label' => 'Reject', 'value' => 'reject', 'shortcut' => '2'],
                ['label' => 'Needs Review', 'value' => 'needs_review', 'shortcut' => '3'],
            ],
            $choices
        );
    }

    public function testDecisionLabelFallsBackToRawValueWhenChoiceIsUnknown() {
        $choices = normalizeDecisionChoices([
            ['label' => 'Accept', 'value' => 'accept', 'shortcut' => '1'],
        ]);

        $this->assertSame('Accept', getDecisionLabel('accept', $choices));
        $this->assertSame('unknown_value', getDecisionLabel('unknown_value', $choices));
    }

    public function testCombineAnnotatorDecisionsRequiresMatchingLengths() {
        $this->expectException(InvalidArgumentException::class);

        combineAnnotatorDecisions(['alice'], []);
    }

    public function testNominalCohenKappaReturnsPerfectAgreement() {
        $kappa = calculateNominalCohenKappa([
            ['decision1' => 'yes', 'decision2' => 'yes'],
            ['decision1' => 'no', 'decision2' => 'no'],
            ['decision1' => 'maybe', 'decision2' => 'maybe'],
        ]);

        $this->assertSame(1.0, $kappa);
    }

    public function testNominalCohenKappaReturnsZeroForChanceAgreement() {
        $kappa = calculateNominalCohenKappa([
            ['decision1' => 'yes', 'decision2' => 'yes'],
            ['decision1' => 'yes', 'decision2' => 'no'],
            ['decision1' => 'no', 'decision2' => 'yes'],
            ['decision1' => 'no', 'decision2' => 'no'],
        ]);

        $this->assertEqualsWithDelta(0.0, $kappa, 0.000001);
    }

    public function testNominalKrippendorffsAlphaReturnsPerfectAgreement() {
        $alpha = calculateNominalKrippendorffsAlpha([
            ['yes', 'yes'],
            ['no', 'no'],
            ['maybe', 'maybe', 'maybe'],
        ]);

        $this->assertSame(1.0, $alpha);
    }

    public function testNominalKrippendorffsAlphaHandlesMixedAgreement() {
        $alpha = calculateNominalKrippendorffsAlpha([
            ['yes', 'yes'],
            ['yes', 'no'],
            ['no', 'no'],
        ]);

        $this->assertEqualsWithDelta(0.4444444444, $alpha, 0.000001);
    }

    public function testHighlightSnippetWrapsTheHighlightedText() {
        $highlighted = highlightSnippet('Annotate this snippet', 'snippet');

        $this->assertSame("Annotate this <span class='highlight'>snippet</span>", $highlighted);
    }
}
