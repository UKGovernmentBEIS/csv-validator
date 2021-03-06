<?php

namespace Deblan\CsvValidator;

use Deblan\Csv\CsvParser;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\RecursiveValidator;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;

/**
 * Class Validator.
 *
 * @author Simon Vieille <simon@deblan.fr>
 */
class Validator
{
    /**
     * @var CsvParser
     */
    protected $parser;

    /**
     * @var RecursiveValidator
     */
    protected $validator;

    /**
     * @var array
     */
    protected $fieldConstraints = [];

    /**
     * @var array
     */
    protected $dataConstraints = [];

    /**
     * @var bool
     */
    protected $hasValidate = false;

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var array
     */
    protected $expectedHeaders = [];

    /**
     * Constructor.
     *
     * @param RecursiveValidator $validator
     */
    public function __construct(RecursiveValidator $validator = null)
    {
        if ($validator === null) {
            $validator = Validation::createValidator();
        }

        $this->validator = $validator;
    }

    /**
     * Append a constraint to a specific column.
     *
     * @param int        $key        The column number
     * @param Constraint $constraint The constraint
     *
     * @return Validator
     */
    public function addFieldConstraint($key, Constraint $constraint)
    {
        if (!array_key_exists($key, $this->fieldConstraints)) {
            $this->fieldConstraints[$key] = [];
        }

        $this->fieldConstraints[$key][] = $constraint;

        return $this;
    }

    /**
     * Append a constraint to a specific line.
     *
     * @param Constraint $constraint The constraint
     *
     * @return Validator
     */
    public function addDataConstraint(Constraint $constraint)
    {
        $this->dataConstraints[] = $constraint;

        return $this;
    }

    /**
     * Set the expected legend.
     *
     * @param array $legend Expected legend
     *
     * @return Validator
     */
    public function setExpectedHeaders(array $legend)
    {
        $this->expectedHeaders = $legend;

        return $this;
    }

    /**
     * Run the validation.
     *
     * @param CsvParser $parser
     */
    public function validate(CsvParser $parser)
    {
        if ($this->parser !== $parser) {
            $this->parser = $parser;
            $this->errors = [];
            $this->hasValidate = false;
        } elseif ($this->hasValidate) {
            return;
        }

        $this->validateHeaders();
        $this->validateDatas();
        $this->validateFields();

        $this->hasValidate = true;
    }

    /**
     * Validates the legend.
     */
    protected function validateHeaders()
    {
        if (!$this->parser->getHasHeaders()) {
            return;
        }

        if (empty($this->expectedHeaders)) {
            return;
        }

        if ($this->parser->getHeaders() !== $this->expectedHeaders) {
            $this->mergeErrorMessage('Invalid legend.', 1);
        }
    }

    /**
     * Validates datas.
     */
    protected function validateDatas()
    {
        if (empty($this->dataConstraints)) {
            return;
        }

        foreach ($this->parser->getDatas() as $line => $data) {
            foreach ($this->dataConstraints as $constraint) {
                $violations = $this->validator->validate($data, $constraint);

                $this->mergeViolationsMessages($violations, $this->getTrueLine($line));
            }
        }
    }

    /**
     * Validates fields.
     */
    protected function validateFields()
    {
        if (empty($this->fieldConstraints)) {
            return;
        }

        foreach ($this->parser->getDatas() as $line => $data) {
            foreach ($this->fieldConstraints as $key => $constraints) {
                if (!isset($data[$key])) {
                    $column = $this->getTrueColunm($key);
                    $this->mergeErrorMessage(
                        sprintf('Field "%s" does not exist.', $column),
                        $this->getTrueLine($line),
                        $column
                    );
                } else {
                    foreach ($constraints as $constraint) {
                        $violations = $this->validator->validate($data[$key], $constraint);

                        $this->mergeViolationsMessages(
                            $violations,
                            $this->getTrueLine($line),
                            $this->getTrueColunm($key)
                        );
                    }
                }
            }
        }
    }

    /**
     * Add violations.
     *
     * @param ConstraintViolationList $violations
     * @param int                     $line       The line of the violations
     * @param int|null                $key        The column of the violations
     */
    protected function mergeViolationsMessages(ConstraintViolationList $violations, $line, $key = null)
    {
        if (count($violations) === 0) {
            return;
        }

        foreach ($violations as $violation) {
            $this->errors[] = $this->generateViolation($line, $key, $violation);
        }
    }

    /**
     * Create and append a violation from a string error.
     *
     * @param string   $message The error message
     * @param int      $line    The line of the violations
     * @param int|null $key     The column of the violations
     */
    protected function mergeErrorMessage($message, $line, $key = null)
    {
        $violation = $this->generateConstraintViolation($message);
        $this->errors[] = $this->generateViolation($line, $key, $violation);
    }

    /**
     * Returns the validation status.
     *
     * @return bool
     * @throw RuntimeException No validation yet
     */
    public function isValid()
    {
        if (!$this->hasValidate) {
            throw new \RuntimeException('You must validate before.');
        }

        return empty($this->errors);
    }

    /**
     * Returns the errors.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Generate a ConstraintViolation.
     *
     * @param string $message The error message
     *
     * @return ConstraintViolation
     */
    protected function generateConstraintViolation($message)
    {
        return new ConstraintViolation($message, $message, [], null, '', null);
    }

    /**
     * Generate a Violation.
     *
     * @param string   $message The error message
     * @param int      $line    The line of the violations
     * @param int|null $key     The column of the violations
     *
     * @return Violation
     */
    protected function generateViolation($line, $key, ConstraintViolation $violation)
    {
        return new Violation($line, $key, $violation);
    }

    /**
     * Get the true line number of an error.
     *
     * @param int $line
     *
     * @return int
     */
    protected function getTrueLine($line)
    {
        if ($this->parser->getHasHeaders()) {
            ++$line;
        }

        return ++$line;
    }

    /**
     * Get the true culumn number of an error.
     *
     * @param int $key
     *
     * @return int
     */
    protected function getTrueColunm($key)
    {
        return ++$key;
    }
}
