<?php
/**
 * OpenRegister ValidateObject Handler
 *
 * Handler class responsible for validating objects against their schemas.
 * This handler provides methods for:
 * - JSON Schema validation of objects
 * - Custom validation rule processing
 * - Schema resolution and caching
 * - Validation error handling and formatting
 * - Support for external schema references
 * - Format validation (e.g., BSN numbers)
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\ObjectHandlers;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\File;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Opis\Uri\Uri;
use stdClass;

/**
 * Handler class for validating objects in the OpenRegister application.
 *
 * This handler is responsible for validating objects against their schemas,
 * including custom validation rules and error handling.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\ObjectHandlers
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   1.0.0
 * @copyright 2024 Conduction b.v.
 */
class ValidateObject
{
    /**
     * Default validation error message.
     *
     * @var string
     */
    public const VALIDATION_ERROR_MESSAGE = 'Invalid object';


    /**
     * Constructor for ValidateObject handler.
     *
     * @param IURLGenerator $urlGenerator URL generator service.
     * @param IAppConfig    $config       Application configuration service.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $config,
        private readonly SchemaMapper $schemaMapper,
    ) {

    }//end __construct()


    /**
     * Validates an object against a schema.
     *
     * @param array           $object       The object to validate.
     * @param Schema|int|null $schema       The schema or schema ID to validate against.
     * @param object          $schemaObject A custom schema object for validation.
     * @param int             $depth        The depth level for validation.
     *
     * @return ValidationResult The result of the validation.
     */
    public function validateObject(
        array $object,
        Schema | int | string | null $schema=null,
        object $schemaObject=new stdClass(),
        int $depth=0
    ): ValidationResult {

        // Use == because === will never be true when comparing stdClass-instances
        if ($schemaObject == new stdClass()) {
            if ($schema instanceof Schema) {
                $schemaObject = $schema->getSchemaObject($this->urlGenerator);
            } else if (is_int($schema) === true || is_string($schema) === true) {
                $schemaObject = $this->schemaMapper->find($schema)->getSchemaObject($this->urlGenerator);
            }
        }

        // If schemaObject reuired is empty unset it.
        if (isset($schemaObject->required) === true && empty($schemaObject->required) === true) {
            unset($schemaObject->required);
        }

        // If there are no properties, we don't need to validate.
        if (isset($schemaObject->properties) === false || empty($schemaObject->properties) === true) {
            // Return a ValidationResult with null data indicating success.
            return new ValidationResult(null, null);
        }

        // @todo This should be done earlier
        unset($object['extend'], $object['filters']);

        // Remove empty properties and empty arrays from the object.
        $object = array_filter($object, function ($value) {
            // Check if the value is not an empty array or an empty property.
            // @todo we are filtering out arrays here, but we should not. This should be fixed in the validator.
            return !(is_array($value) && empty($value)) && $value !== null && $value !== '' && is_array($value) === false;
        });

        $validator = new Validator();
        $validator->setMaxErrors(100);
        $validator->parser()->getFormatResolver()->register('string', 'bsn', new BsnFormat());
        $validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);

        return $validator->validate(json_decode(json_encode($object)), $schemaObject);

    }//end validateObject()


    /**
     * Resolves a schema from a given URI.
     *
     * @param Uri $uri The URI pointing to the schema.
     *
     * @return string The schema content in JSON format.
     *
     * @throws GuzzleException If there is an error during schema fetching.
     */
    public function resolveSchema(Uri $uri): string
    {
        // Local schema resolution.
        if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
            && str_contains($uri->path(), '/api/schemas') === true
        ) {
            $exploded = explode('/', $uri->path());
            $schema   = $this->schemaMapper->find(end($exploded));

            return json_encode($schema->getSchemaObject($this->urlGenerator));
        }

        // File schema resolution.
        if ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host()
            && str_contains($uri->path(), '/api/files/schema') === true
        ) {
            return File::getSchema($this->urlGenerator);
        }

        // External schema resolution.
        if ($this->config->getValueBool('openregister', 'allowExternalSchemas') === true) {
            $client = new Client();
            $result = $client->get(\GuzzleHttp\Psr7\Uri::fromParts($uri->components()));

            return $result->getBody()->getContents();
        }

        return '';

    }//end resolveSchema()


    /**
     * Validates custom rules for an object against its schema.
     *
     * @param array  $object The object to validate.
     * @param Schema $schema The schema containing custom rules.
     *
     * @return void
     *
     * @throws ValidationException If validation fails.
     */
    private function validateCustomRules(array $object, Schema $schema): void
    {
        $customRules = $schema->getCustomRules();
        if (empty($customRules) === true) {
            return;
        }

        foreach ($customRules as $rule) {
            if (isset($rule['type']) === true && $rule['type'] === 'regex') {
                $pattern = $rule['pattern'];
                $value   = $object[$rule['property']] ?? null;

                if ($value !== null && preg_match($pattern, $value) === false) {
                    throw new ValidationException(
                        $rule['message'] ?? self::VALIDATION_ERROR_MESSAGE,
                        $rule['property']
                    );
                }
            }
        }

    }//end validateCustomRules()


    /**
     * Handles validation exceptions by formatting them into a JSON response.
     *
     * @param ValidationException|CustomValidationException $exception The validation exception.
     *
     * @return JSONResponse The formatted error response.
     */
    public function handleValidationException(ValidationException | CustomValidationException $exception): JSONResponse
    {
        $errors = [];
        if ($exception instanceof ValidationException) {
            $errors[] = [
                'property' => method_exists($exception, 'getProperty') ? $exception->getProperty() : null,
                'message'  => $exception->getMessage(),
                'errors' => (new ErrorFormatter())->format($exception->getErrors()),
            ];
        } else {
            foreach ($exception->getErrors() as $error) {
                $errors[] = [
                    'property' => isset($error['property']) ? $error['property'] : null,
                    'message'  => $error['message'],
                ];
            }
        }

        return new JSONResponse(
            [
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $errors,
            ],
            400
        );

    }//end handleValidationException()


}//end class
