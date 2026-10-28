<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Maps OpenCode v2 interactive forms onto the question shape the status store
 * already speaks. v2 replaced the v1 question API with forms: the `question`
 * tool creates a form whose `metadata.kind` is `question` and whose fields
 * carry the question text, the choices and the answer keys.
 */
final class OpenCodeForm
{
    /**
     * @param array<string,mixed> $form
     * @return array<int,array<string,mixed>>|null null when this is not a question form
     */
    public static function questions(array $form): ?array
    {
        if (($form['metadata']['kind'] ?? null) !== 'question') return null;

        $questions = [];
        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null)) continue;

            $options = [];
            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                if (!is_array($option) || !is_string($option['label'] ?? null)) continue;
                $options[] = [
                    'label' => $option['label'],
                    'value' => is_string($option['value'] ?? null) ? $option['value'] : $option['label'],
                ];
            }

            $title = is_string($field['title'] ?? null) ? $field['title'] : '';
            $description = is_string($field['description'] ?? null) ? $field['description'] : '';
            $questions[] = [
                'key' => $field['key'],
                'header' => $title,
                'question' => $description !== '' ? $description : $title,
                'options' => $options,
                'multiple' => ($field['type'] ?? null) === 'multiselect',
                'custom' => ($field['custom'] ?? false) === true,
            ];
        }

        return $questions === [] ? null : $questions;
    }

    /**
     * Field key => answer value for POST .../form/{id}/reply. A single-choice
     * answer is a string, a multiselect answer an array of values.
     *
     * @param array<int,array<string,mixed>> $questions
     * @param array<int,array<int,string>> $answers one label list per question
     * @return array<string,string|array<int,string>>
     */
    public static function answer(array $questions, array $answers): array
    {
        $map = [];
        foreach ($questions as $index => $question) {
            $key = is_string($question['key'] ?? null) ? $question['key'] : 'q' . $index;
            $values = [];
            foreach ($answers[$index] ?? [] as $label) {
                $values[] = self::option_value($question, $label);
            }
            $map[$key] = ($question['multiple'] ?? false) === true ? $values : ($values[0] ?? '');
        }
        return $map;
    }

    /**
     * Free-text answers keep the typed label; choices map to their option value.
     * @param array<string,mixed> $question
     */
    private static function option_value(array $question, string $label): string
    {
        foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
            if (!is_array($option) || ($option['label'] ?? null) !== $label) continue;
            return is_string($option['value'] ?? null) ? $option['value'] : $label;
        }
        return $label;
    }
}
