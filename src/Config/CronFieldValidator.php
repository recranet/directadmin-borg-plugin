<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class CronFieldValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CronField) {
            throw new UnexpectedTypeException($constraint, CronField::class);
        }
        if ($value === null || $value === '') {
            return;
        }
        if (!\is_string($value)) {
            $this->addViolation($constraint);

            return;
        }
        if (!$this->isValid($value, $constraint->min, $constraint->max)) {
            $this->addViolation($constraint);
        }
    }

    private function addViolation(CronField $constraint): void
    {
        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ label }}', $constraint->label)
            ->addViolation();
    }

    private function isValid(string $field, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            if ($part === '') {
                return false;
            }

            if (str_contains($part, '/')) {
                [$part, $step] = explode('/', $part, 2);
                if (!ctype_digit($step) || (int) $step < 1 || (int) $step > $max) {
                    return false;
                }
            }

            if ($part === '*') {
                continue;
            }

            $bounds = explode('-', $part);
            if (\count($bounds) > 2) {
                return false;
            }
            foreach ($bounds as $bound) {
                if (!ctype_digit($bound) || (int) $bound < $min || (int) $bound > $max) {
                    return false;
                }
            }
            if (\count($bounds) === 2 && (int) $bounds[0] > (int) $bounds[1]) {
                return false;
            }
        }

        return true;
    }
}
