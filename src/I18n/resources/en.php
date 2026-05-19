<?php

declare(strict_types=1);

/*
 * Framework English catalog (the fallback locale).
 *
 * Every string here is byte-identical to the value that used to be
 * hard-coded in the Validation / Router / Scaffold classes, so an app that
 * never configures i18n keeps exactly the pre-i18n English output.
 *
 * Pluralized messages use the `one|other` pipe form consumed by
 * Translator::transChoice(); `{name}` placeholders are filled from params.
 */

return [
    // Validation — base
    'relayer.validation.required' => 'Required.',

    // Validation — string
    'relayer.validation.string.min' => 'Must be at least {min} character.|Must be at least {min} characters.',
    'relayer.validation.string.max' => 'Must be at most {max} character.|Must be at most {max} characters.',
    'relayer.validation.string.length' => 'Must be exactly {length} character.|Must be exactly {length} characters.',
    'relayer.validation.string.regex' => 'Invalid format.',
    'relayer.validation.string.email' => 'Please enter a valid email address.',
    'relayer.validation.string.url' => 'Please enter a valid URL.',
    'relayer.validation.string.type' => 'Must be a string.',

    // Validation — int
    'relayer.validation.int.min' => 'Must be {value} or greater.',
    'relayer.validation.int.max' => 'Must be {value} or less.',
    'relayer.validation.int.positive' => 'Must be greater than 0.',
    'relayer.validation.int.non_negative' => 'Must be 0 or greater.',
    'relayer.validation.int.type' => 'Must be an integer.',

    // Validation — float
    'relayer.validation.float.min' => 'Must be {value} or greater.',
    'relayer.validation.float.max' => 'Must be {value} or less.',
    'relayer.validation.float.positive' => 'Must be greater than 0.',
    'relayer.validation.float.non_negative' => 'Must be 0 or greater.',
    'relayer.validation.float.type' => 'Must be a number.',

    // Validation — bool
    'relayer.validation.bool.true' => 'Must be true.',
    'relayer.validation.bool.type' => 'Must be a boolean.',

    // Validation — array
    'relayer.validation.array.min' => 'Must contain at least {min} item.|Must contain at least {min} items.',
    'relayer.validation.array.max' => 'Must contain at most {max} item.|Must contain at most {max} items.',
    'relayer.validation.array.non_empty' => 'Must not be empty.',
    'relayer.validation.array.type' => 'Must be an array.',

    // Validation — enum / object
    'relayer.validation.enum' => 'Must be one of: {values}.',
    'relayer.validation.object.type' => 'Must be an object.',

    // HTTP reason phrases (keyed by status; mirrors HttpException::reasonPhrase)
    'relayer.http.400' => 'Bad Request',
    'relayer.http.401' => 'Unauthorized',
    'relayer.http.402' => 'Payment Required',
    'relayer.http.403' => 'Forbidden',
    'relayer.http.404' => 'Not Found',
    'relayer.http.405' => 'Method Not Allowed',
    'relayer.http.406' => 'Not Acceptable',
    'relayer.http.408' => 'Request Timeout',
    'relayer.http.409' => 'Conflict',
    'relayer.http.410' => 'Gone',
    'relayer.http.413' => 'Payload Too Large',
    'relayer.http.415' => 'Unsupported Media Type',
    'relayer.http.418' => "I'm a teapot",
    'relayer.http.422' => 'Unprocessable Entity',
    'relayer.http.423' => 'Locked',
    'relayer.http.429' => 'Too Many Requests',
    'relayer.http.451' => 'Unavailable For Legal Reasons',
    'relayer.http.500' => 'Internal Server Error',
    'relayer.http.501' => 'Not Implemented',
    'relayer.http.502' => 'Bad Gateway',
    'relayer.http.503' => 'Service Unavailable',
    'relayer.http.504' => 'Gateway Timeout',
    'relayer.http.client_error' => 'Client Error',
    'relayer.http.server_error' => 'Server Error',
    // The built-in HTML 404 page body (distinct from the API "Not Found").
    'relayer.http.page_not_found' => 'Page not found',
];
