<?php

namespace Martingalian\Core\Concerns;

use Illuminate\Support\Facades\Validator;

trait ValidatesAttributes
{
    public function validate($data, $rules)
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $failedRules = $validator->failed();
            $message = 'Arguments validation failed.';

            foreach ($failedRules as $field => $failures) {
                foreach ($failures as $rule => $details) {
                    $value = $data[$field] ?? 'undefined';
                    $ruleDetails = is_array($details) ? json_encode($details) : $details;
                    $message .= "\nField: {$field}, Value: {$value}, Failed Rule: {$rule}, Rule Details: {$ruleDetails}";
                }
            }

            throw new \InvalidArgumentException($message);
        }
    }
}
