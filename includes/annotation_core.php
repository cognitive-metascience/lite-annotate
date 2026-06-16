<?php

function getDefaultDecisionChoices() {
    return [
        ['label' => 'Yes', 'value' => 'yes', 'shortcut' => '1'],
        ['label' => 'No', 'value' => 'no', 'shortcut' => '2'],
    ];
}

function slugifyChoiceValue($label) {
    $value = strtolower(trim((string) $label));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim($value, '_');

    return $value !== '' ? $value : 'choice';
}

function extractChoiceLabel($choice) {
    return trim((string) ($choice['label'] ?? ''));
}

function extractChoiceValue($choice, $label) {
    $value = trim((string) ($choice['value'] ?? ''));

    if ($value === '') {
        $value = slugifyChoiceValue($label);
    }

    return $value;
}

function determineChoiceShortcut($choice, $index) {
    $rawShortcut = array_key_exists('shortcut', $choice) ? trim((string) $choice['shortcut']) : '';

    if ($rawShortcut !== '') {
        return $rawShortcut;
    }

    return $index < 9 ? (string) ($index + 1) : '';
}

function buildNormalizedChoice($choice, $index, $usedValues, $usedShortcuts) {
    $normalizedChoice = null;

    if (is_array($choice)) {
        $label = extractChoiceLabel($choice);
        if ($label !== '') {
            $value = extractChoiceValue($choice, $label);
            if (!isset($usedValues[$value])) {
                $shortcut = determineChoiceShortcut($choice, $index);
                if ($shortcut !== '' && isset($usedShortcuts[$shortcut])) {
                    $shortcut = '';
                }

                $normalizedChoice = [
                    'label' => $label,
                    'value' => $value,
                    'shortcut' => $shortcut,
                ];
            }
        }
    }

    return $normalizedChoice;
}

function registerNormalizedChoice($choice, &$usedValues, &$usedShortcuts) {
    $usedValues[$choice['value']] = true;

    if ($choice['shortcut'] !== '') {
        $usedShortcuts[$choice['shortcut']] = true;
    }
}

function normalizeDecisionChoices($choices) {
    if (!is_array($choices)) {
        return getDefaultDecisionChoices();
    }

    $normalizedChoices = [];
    $usedValues = [];
    $usedShortcuts = [];

    foreach ($choices as $index => $choice) {
        $normalizedChoice = buildNormalizedChoice($choice, $index, $usedValues, $usedShortcuts);
        if ($normalizedChoice === null) {
            continue;
        }

        registerNormalizedChoice($normalizedChoice, $usedValues, $usedShortcuts);
        $normalizedChoices[] = $normalizedChoice;
    }

    return $normalizedChoices ?: getDefaultDecisionChoices();
}

function parseDecisionChoices($rawChoiceSchema) {
    if (!is_string($rawChoiceSchema) || trim($rawChoiceSchema) === '') {
        return getDefaultDecisionChoices();
    }

    $decodedChoices = json_decode($rawChoiceSchema, true);
    if (!is_array($decodedChoices)) {
        return getDefaultDecisionChoices();
    }

    if (isset($decodedChoices['choices']) && is_array($decodedChoices['choices'])) {
        $decodedChoices = $decodedChoices['choices'];
    }

    return normalizeDecisionChoices($decodedChoices);
}

function buildDecisionChoicesFromText($rawChoiceText) {
    if (!is_string($rawChoiceText) || trim($rawChoiceText) === '') {
        return getDefaultDecisionChoices();
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($rawChoiceText));
    $choices = [];

    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $trimmedLine));
        $label = $parts[0] ?? '';
        $shortcut = $parts[1] ?? '';
        $value = $parts[2] ?? '';

        $choices[] = [
            'label' => $label,
            'shortcut' => $shortcut,
            'value' => $value,
        ];
    }

    return normalizeDecisionChoices($choices);
}

function serializeDecisionChoices($choices) {
    return json_encode(normalizeDecisionChoices($choices), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function serializeDecisionChoicesToText($choices) {
    $normalizedChoices = normalizeDecisionChoices($choices);
    $lines = [];

    foreach ($normalizedChoices as $choice) {
        $lines[] = implode(' | ', [
            $choice['label'],
            $choice['shortcut'],
            $choice['value'],
        ]);
    }

    return implode(PHP_EOL, $lines);
}

function findDecisionChoice($value, $choices) {
    $decisionValue = (string) $value;

    foreach ($choices as $choice) {
        if (!is_array($choice)) {
            continue;
        }

        if (($choice['value'] ?? null) === $decisionValue) {
            return $choice;
        }
    }

    return null;
}

function isValidDecisionChoice($value, $choices) {
    return findDecisionChoice($value, $choices) !== null;
}

function combineAnnotatorDecisions($annotators, $decisions) {
    if (count($annotators) !== count($decisions)) {
        throw new InvalidArgumentException('Annotator and decision counts must match.');
    }

    $annotations = [];
    foreach ($annotators as $index => $annotator) {
        $annotations[(string) $annotator] = $decisions[$index];
    }

    return $annotations;
}

function collectNominalPairStats($pairs, $leftKey, $rightKey) {
    $stats = [
        'leftCounts' => [],
        'rightCounts' => [],
        'observedMatches' => 0,
        'pairCount' => 0,
    ];

    foreach ($pairs as $pair) {
        if (!is_array($pair) || !array_key_exists($leftKey, $pair) || !array_key_exists($rightKey, $pair)) {
            throw new InvalidArgumentException('Each pair must contain both decision keys.');
        }

        $leftDecision = (string) $pair[$leftKey];
        $rightDecision = (string) $pair[$rightKey];

        if (!isset($stats['leftCounts'][$leftDecision])) {
            $stats['leftCounts'][$leftDecision] = 0;
        }
        if (!isset($stats['rightCounts'][$rightDecision])) {
            $stats['rightCounts'][$rightDecision] = 0;
        }

        $stats['leftCounts'][$leftDecision]++;
        $stats['rightCounts'][$rightDecision]++;
        $stats['pairCount']++;

        if ($leftDecision === $rightDecision) {
            $stats['observedMatches']++;
        }
    }

    return $stats;
}

function calculateExpectedAgreementFromCounts($leftCounts, $rightCounts, $pairCount) {
    $expectedAgreement = 0.0;
    $categories = array_unique(array_merge(array_keys($leftCounts), array_keys($rightCounts)));

    foreach ($categories as $category) {
        $expectedAgreement += (($leftCounts[$category] ?? 0) / $pairCount) * (($rightCounts[$category] ?? 0) / $pairCount);
    }

    return $expectedAgreement;
}

function resolveNominalKappa($observedAgreement, $expectedAgreement) {
    if (abs(1.0 - $expectedAgreement) < 1.0E-12) {
        return $observedAgreement === 1.0 ? 1.0 : null;
    }

    return ($observedAgreement - $expectedAgreement) / (1.0 - $expectedAgreement);
}

function calculateNominalCohenKappa($pairs, $leftKey = 'decision1', $rightKey = 'decision2') {
    if (!is_array($pairs) || count($pairs) === 0) {
        return null;
    }

    $stats = collectNominalPairStats($pairs, $leftKey, $rightKey);

    if ($stats['pairCount'] === 0) {
        return null;
    }

    $observedAgreement = $stats['observedMatches'] / $stats['pairCount'];
    $expectedAgreement = calculateExpectedAgreementFromCounts($stats['leftCounts'], $stats['rightCounts'], $stats['pairCount']);

    return resolveNominalKappa($observedAgreement, $expectedAgreement);
}

function filterUnitRatings($unitRatings) {
    if (!is_array($unitRatings)) {
        throw new InvalidArgumentException('Each unit must be an array of ratings.');
    }

    return array_values(array_filter($unitRatings, static function ($rating) {
        return $rating !== null && $rating !== '';
    }));
}

function addCoincidenceValue(&$coincidenceMatrix, &$totalCoincidences, $categoryA, $categoryB, $increment) {
    if ($increment === 0.0) {
        return;
    }

    if (!isset($coincidenceMatrix[$categoryA])) {
        $coincidenceMatrix[$categoryA] = [];
    }
    if (!isset($coincidenceMatrix[$categoryA][$categoryB])) {
        $coincidenceMatrix[$categoryA][$categoryB] = 0.0;
    }

    $coincidenceMatrix[$categoryA][$categoryB] += $increment;
    $totalCoincidences += $increment;
}

function addUnitCoincidences(&$coincidenceMatrix, &$totalCoincidences, $unitRatings) {
    $filteredRatings = filterUnitRatings($unitRatings);
    $unitSize = count($filteredRatings);

    if ($unitSize < 2) {
        return;
    }

    $ratingCounts = array_count_values(array_map('strval', $filteredRatings));

    foreach ($ratingCounts as $categoryA => $countA) {
        foreach ($ratingCounts as $categoryB => $countB) {
            $increment = $categoryA === $categoryB
                ? ($countA * ($countB - 1)) / ($unitSize - 1)
                : ($countA * $countB) / ($unitSize - 1);

            addCoincidenceValue($coincidenceMatrix, $totalCoincidences, $categoryA, $categoryB, $increment);
        }
    }
}

function buildNominalCoincidenceMatrix($units) {
    $coincidenceMatrix = [];
    $totalCoincidences = 0.0;

    foreach ($units as $unitRatings) {
        addUnitCoincidences($coincidenceMatrix, $totalCoincidences, $unitRatings);
    }

    return [$coincidenceMatrix, $totalCoincidences];
}

function calculateCategoryTotals($coincidenceMatrix) {
    $categoryTotals = [];

    foreach ($coincidenceMatrix as $category => $row) {
        $categoryTotals[$category] = array_sum($row);
    }

    return $categoryTotals;
}

function calculateObservedDisagreement($coincidenceMatrix, $totalCoincidences) {
    $observedDisagreement = 0.0;

    foreach ($coincidenceMatrix as $categoryA => $row) {
        foreach ($row as $categoryB => $coincidences) {
            if ($categoryA !== $categoryB) {
                $observedDisagreement += $coincidences;
            }
        }
    }

    return $observedDisagreement / $totalCoincidences;
}

function calculateExpectedDisagreement($categoryTotals, $totalCoincidences) {
    $expectedDisagreement = 0.0;

    foreach ($categoryTotals as $categoryA => $countA) {
        foreach ($categoryTotals as $categoryB => $countB) {
            if ($categoryA !== $categoryB) {
                $expectedDisagreement += $countA * $countB;
            }
        }
    }

    return $expectedDisagreement / ($totalCoincidences * ($totalCoincidences - 1));
}

function resolveNominalAlpha($observedDisagreement, $expectedDisagreement, $totalCoincidences) {
    if ($totalCoincidences <= 1.0) {
        return 1.0;
    }

    if (abs($expectedDisagreement) < 1.0E-12) {
        return $observedDisagreement === 0.0 ? 1.0 : null;
    }

    return 1.0 - ($observedDisagreement / $expectedDisagreement);
}

function calculateNominalKrippendorffsAlpha($units) {
    if (!is_array($units) || count($units) === 0) {
        return null;
    }

    [$coincidenceMatrix, $totalCoincidences] = buildNominalCoincidenceMatrix($units);

    if ($totalCoincidences === 0.0) {
        return null;
    }

    $categoryTotals = calculateCategoryTotals($coincidenceMatrix);
    $observedDisagreement = calculateObservedDisagreement($coincidenceMatrix, $totalCoincidences);
    $expectedDisagreement = calculateExpectedDisagreement($categoryTotals, $totalCoincidences);

    return resolveNominalAlpha($observedDisagreement, $expectedDisagreement, $totalCoincidences);
}

function getDecisionLabel($value, $choices) {
    $choice = findDecisionChoice($value, $choices);

    if ($choice !== null) {
        return $choice['label'];
    }

    return (string) $value;
}
