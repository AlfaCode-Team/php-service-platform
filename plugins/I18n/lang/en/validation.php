<?php

declare(strict_types=1);

/**
 * English validation messages. :field and rule params (:min, :max, :other,
 * :values) are substituted by the Validator.
 */
return [
    'required'  => 'The :field field is required.',
    'string'    => 'The :field field must be a string.',
    'integer'   => 'The :field field must be an integer.',
    'numeric'   => 'The :field field must be numeric.',
    'boolean'   => 'The :field field must be true or false.',
    'array'     => 'The :field field must be an array.',
    'email'     => 'The :field field must be a valid email address.',
    'url'       => 'The :field field must be a valid URL.',
    'min'       => 'The :field field must be at least :min.',
    'max'       => 'The :field field must not be greater than :max.',
    'between'   => 'The :field field must be between :min and :max.',
    'in'        => 'The selected :field is invalid.',
    'regex'     => 'The :field field format is invalid.',
    'same'      => 'The :field field must match :other.',
    'different' => 'The :field field must be different from :other.',
    'confirmed' => 'The :field field confirmation does not match.',
];
