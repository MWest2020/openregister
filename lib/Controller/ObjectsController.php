<?php
/**
 * Class ObjectsController
 *
 * Controller for managing object operations in the OpenRegister app.
 * Provides CRUD functionality for objects within registers and schemas.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCP\AppFramework\Http\DataDownloadResponse;
/**
 * Class ObjectsController
 */
class ObjectsController extends Controller
{

    /**
     * Export service for handling data exports
     *
     * @var ExportService
     */
    private readonly ExportService $exportService;

    /**
     * Import service for handling data imports
     *
     * @var ImportService
     */
    private readonly ImportService $importService;

    /**
     * Constructor for the ObjectsController
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param IAppConfig         $config             The app configuration object
     * @param IAppManager        $appManager         The app manager
     * @param ContainerInterface $container          The DI container
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param AuditTrailMapper   $auditTrailMapper   The audit trail mapper
     * @param ObjectService      $objectService      The object service
     * @param IUserSession       $userSession        The user session
     * @param ExportService      $exportService      The export service
     * @param ImportService      $importService      The import service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        ExportService $exportService,
        ImportService $importService
    ) {
        parent::__construct($appName, $request);
        $this->exportService = $exportService;
        $this->importService = $importService;
    }


    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );

    }//end page()


    /**
     * Private helper method to handle pagination of results.
     *
     * This method paginates the given results array based on the provided total, limit, offset, and page parameters.
     * It calculates the number of pages, sets the appropriate offset and page values, and returns the paginated results
     * along with metadata such as total items, current page, total pages, limit, and offset.
     *
     * @param array    $results The array of objects to paginate.
     * @param int|null $total   The total number of items (before pagination). Defaults to 0.
     * @param int|null $limit   The number of items per page. Defaults to 20.
     * @param int|null $offset  The offset of items. Defaults to 0.
     * @param int|null $page    The current page number. Defaults to 1.
     *
     * @return array The paginated results with metadata.
     *
     * @phpstan-param  array<int, mixed> $results
     * @phpstan-return array<string, mixed>
     * @psalm-param    array<int, mixed> $results
     * @psalm-return   array<string, mixed>
     */
    private function paginate(array $results, ?int $total=0, ?int $limit=20, ?int $offset=0, ?int $page=1): array
    {
        // Ensure we have valid values (never null).
        $total = max(0, $total ?? 0);
        $limit = max(1, $limit ?? 20);
        // Minimum limit of 1.
        $offset = max(0, $offset ?? 0);
        $page   = max(1, $page ?? 1);
        // Minimum page of 1        // Calculate the number of pages (minimum 1 page).
        $pages = max(1, ceil($total / $limit));

        // If we have a page but no offset, calculate the offset.
        if ($offset === 0) {
            $offset = ($page - 1) * $limit;
        }

        // If we have an offset but page is 1, calculate the page.
        if ($page === 1 && $offset > 0) {
            $page = floor($offset / $limit) + 1;
        }

        // If total is smaller than the number of results, set total to the number of results.
        // @todo: this is a hack to ensure the pagination is correct when the total is not known. That sugjest that the underlaying count service has a problem that needs to be fixed instead
        if ($total < count($results)) {
            $total = count($results);
            $pages = max(1, ceil($total / $limit));
        }

        // Initialize the results array with pagination information.
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        // Add next/prev page URLs if applicable.
        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages.
        if ($page < $pages) {
            $nextPage = $page + 1;
            $nextUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$nextPage, $currentUrl);
            if (strpos($nextUrl, 'page=') === false) {
                $nextUrl .= (strpos($nextUrl, '?') === false ? '?' : '&').'page='.$nextPage;
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page.
        if ($page > 1) {
            $prevPage = $page - 1;
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page='.$prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= (strpos($prevUrl, '?') === false ? '?' : '&').'page='.$prevPage;
            }

            $paginatedResults['prev'] = $prevUrl;
        }

        return $paginatedResults;

    }//end paginate()


    /**
     * Helper method to get configuration array from the current request
     *
     * @param string|null $register Optional register identifier
     * @param string|null $schema   Optional schema identifier
     * @param array|null  $ids      Optional array of specific IDs to filter
     *
     * @return array Configuration array containing:
     *               - limit: (int) Maximum number of items per page
     *               - offset: (int|null) Number of items to skip
     *               - page: (int|null) Current page number
     *               - filters: (array) Filter parameters
     *               - sort: (array) Sort parameters
     *               - search: (string|null) Search term
     *               - extend: (array|null) Properties to extend
     *               - fields: (array|null) Fields to include
     *               - unset: (array|null) Fields to exclude
     *               - register: (string|null) Register identifier
     *               - schema: (string|null) Schema identifier
     *               - ids: (array|null) Specific IDs to filter
     */
    private function getConfig(?string $register=null, ?string $schema=null, ?array $ids=null): array
    {
        $params = $this->request->getParams();

        unset($params['id']);
        unset($params['_route']);

        // Extract and normalize parameters.
        $limit  = (int) ($params['limit'] ?? $params['_limit'] ?? 20);
        $offset = isset($params['offset']) ? (int) $params['offset'] : (isset($params['_offset']) ? (int) $params['_offset'] : null);
        $page   = isset($params['page']) ? (int) $params['page'] : (isset($params['_page']) ? (int) $params['_page'] : null);

        // If we have a page but no offset, calculate the offset.
        if ($page !== null && $offset === null) {
            $offset = ($page - 1) * $limit;
        }

        return [
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $params,
            'sort'    => ($params['order'] ?? $params['_order'] ?? []),
            'search'  => ($params['_search'] ?? null),
            'extend'  => ($params['extend'] ?? $params['_extend'] ?? null),
            'fields'  => ($params['fields'] ?? $params['_fields'] ?? null),
            'unset'   => ($params['unset'] ?? $params['_unset'] ?? null),
            'ids'     => $ids,
        ];

    }//end getConfig()


    /**
     * Retrieves a list of all objects for a specific register and schema
     *
     * This method returns a paginated list of objects that match the specified register and schema.
     * It supports filtering, sorting, and pagination through query parameters.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the list of objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Get config and fetch objects.
        $config = $this->getConfig($register, $schema);

        $objectService->setRegister($register)->setSchema($schema);

        // @TODO dit moet netter
        foreach ($config['filters'] as $key => $filter) {
            switch ($key) {
                case 'register':
                    $config['filters'][$key] = $objectService->getRegister();
                    break;
                case 'schema':
                    $config['filters'][$key] = $objectService->getSchema();
                    break;
                default:
                    break;
            }
        }

        $objects = $objectService->findAll($config);

        // Get total count for pagination.
        // $total = $objectService->count($config['filters'], $config['search']);
        $total = $objectService->count($config);
        return new JSONResponse($this->paginate($objects, $total, $config['limit'], $config['offset'], $config['page']));

    }//end index()


    /**
     * Shows a specific object from a register and schema
     *
     * Retrieves and returns a single object from the specified register and schema,
     * with support for field filtering and related object extension.
     *
     * @param string        $id            The object ID
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function show(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract parameters for rendering.
        $extend = ($requestParams['extend'] ?? $requestParams['_extend'] ?? null);
        $filter = ($requestParams['filter'] ?? $requestParams['_filter'] ?? null);
        $fields = ($requestParams['fields'] ?? $requestParams['_fields'] ?? null);

        // Find and validate the object.
        try {
            $object = $this->objectService->find($id, $extend);

            // Render the object with requested extensions and filters.
            return new JSONResponse($object);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        }//end try

    }//end show()


    /**
     * Creates a new object in the specified register and schema
     *
     * Takes the request data, validates it against the schema, and creates a new object
     * in the database. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the created object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function create(
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Save the object.
        try {
            // Use the object service to validate and save the object.
            $objectEntity = $objectService->saveObject(
                object: $object
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the save was successful.
            }
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
           return new JSONResponse($exception->getMessage(), 400);
        }

        // Return the created object.
        return new JSONResponse($objectEntity->jsonSerialize());

    }//end create()


    /**
     * Updates an existing object
     *
     * Takes the request data, validates it against the schema, and updates an existing object
     * in the database. Handles validation errors appropriately.
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param string        $id            The object ID or UUID
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the updated object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function update(
        string $register,
        string $schema,
        string $id,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get object data from request parameters.
        $object = $this->request->getParams();

        // Filter out special parameters and reserved fields.
        // @todo shouldn't this be part of the object service?
        $object = array_filter(
            $object,
            fn ($key) => !str_starts_with($key, '_')
                && !str_starts_with($key, '@')
                && !in_array($key, ['id', 'uuid', 'register', 'schema']),
            ARRAY_FILTER_USE_KEY
        );

        // Check if the object exists and can be updated.
        // @todo shouldn't this be part of the object service?
        try {
            $existingObject = $this->objectService->find($id);

            // Get the resolved register and schema IDs from the ObjectService
            // This ensures proper handling of both numeric IDs and slug identifiers
            $resolvedRegisterId = $objectService->getRegister(); // Returns the current register ID
            $resolvedSchemaId = $objectService->getSchema();     // Returns the current schema ID

            // Verify that the object belongs to the specified register and schema.
            if ((int) $existingObject->getRegister() !== (int) $resolvedRegisterId
                || (int) $existingObject->getSchema() !== (int) $resolvedSchemaId
            ) {
                return new JSONResponse(
                    ['error' => 'Object not found in specified register/schema'],
                    404
                );
            }

            // Check if the object is locked.
            if ($existingObject->isLocked() === true
                && $existingObject->getLockedBy() !== $this->container->get('userId')
            ) {
                // Return a "locked" error with the user who has the lock.
                return new JSONResponse(
                    [
                        'error'    => 'Object is locked by '.$existingObject->getLockedBy(),
                        'lockedBy' => $existingObject->getLockedBy(),
                    ],
                    423
                );
            }
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Not Found'], 404);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            // If there's an issue getting the user ID, continue without the lock check.
        }//end try

        // Update the object.
        try {
            // Use the object service to validate and update the object.
            $objectEntity = $objectService->saveObject(
                object: $object,
                uuid: $id
            );

            // Unlock the object after saving.
            try {
                $this->objectEntityMapper->unlockObject($objectEntity->getId());
            } catch (\Exception $e) {
                // Ignore unlock errors since the update was successful.
            }

            // Return the updated object as JSON.
            return new JSONResponse($objectEntity->jsonSerialize());
        } catch (ValidationException | CustomValidationException $exception) {
            // Handle validation errors.
            return $objectService->handleValidationException(exception: $exception);
        }

    }//end update()


    /**
     * Deletes an object
     *
     * This method deletes an object based on its ID.
     *
     * @param  int           $id            The ID of the object to delete
     * @param  ObjectService $objectService The object service
     * @throws Exception
     *
     * @return JSONResponse An empty JSON response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function destroy(string $id, ObjectService $objectService): JSONResponse
    {
        // Create a log entry.
        $oldObject = $this->objectEntityMapper->find($id);

        // Clone the object to pass as the new state.
        $newObject = clone $oldObject;
        $newObject->delete($this->userSession, $this->request->getParam('deletedReason'), $this->request->getParam('retentionPeriod'));

        // Update the object in the mapper instead of deleting.
        $this->objectEntityMapper->update($newObject);

        // Create an audit trail with both old and new states.
        $this->auditTrailMapper->createAuditTrail(old: $oldObject, new: $newObject);

        // Return the deleted object.
        return new JSONResponse($newObject->jsonSerialize());

    }//end destroy()


    /**
     * Retrieves call logs for a object
     *
     * This method returns all the call logs associated with a object based on its ID.
     *
     * @param int           $id            The ID of the object to retrieve logs for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the call logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @todo Implement contract functionality to handle object contracts and their relationships
     */
    public function contracts(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get request parameters for filtering and searching.
        $requestParams = $this->request->getParams();

        // Extract specific parameters.
        $limit  = (int) ($requestParams['limit'] ?? $requestParams['_limit'] ?? 20);
        $offset = isset($requestParams['offset']) ? (int) $requestParams['offset'] : (isset($requestParams['_offset']) ? (int) $requestParams['_offset'] : null);
        $page   = isset($requestParams['page']) ? (int) $requestParams['page'] : (isset($requestParams['_page']) ? (int) $requestParams['_page'] : null);

        // Return empty paginated response
        return new JSONResponse(
            $this->paginate(
                results: [],
                total: 0,
                limit: $limit,
                offset: $offset,
                page: $page
            )
        );

    }//end contracts()


    /**
     * Retrieves all objects that this object references
     *
     * This method returns all objects that this object uses/references. A -> B means that A (This object) references B (Another object).
     *
     * @param string        $id            The ID of the object to retrieve relations for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the related objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function uses(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the register and schema context first.
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        // Get the relations for the object.
        $relationsArray = $objectService->find($id)->getRelations();
        $relations      = array_values($relationsArray);

        // Check if relations array is empty
        if (empty($relations)) {
            // If relations is empty, set objects to an empty array.
            $objects = [];
            $total   = 0;
            $config = [
                'limit' => 1,
                'offset' => 0,
                'page' => 1,
            ];
        } else {
            // Get config and fetch objects
            $config = $this->getConfig($register, $schema, ids: $relations);

            // We specifacllly want to look outside our current definitions.
            unset($config['filters']['register'], $config['filters']['schema'], $config['limit']);

            $objects = $objectService->findAll($config);
            // Get total count for pagination.
            $total = $objectService->count($config);
        }

        // Return paginated results.
        return new JSONResponse(
            $this->paginate(
                results: $objects,
                total: $total,
                limit: $config['limit'],
                offset: $config['offset'],
                page: $config['page']
            )
        );

    }//end uses()


    /**
     * Retrieves all objects that use a object
     *
     * This method returns all objects that reference (use) this object. B -> A means that B (Another object) references A (This object).
     *
     * @param string        $id            The ID of the object to retrieve uses for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the referenced objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function used(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        // Get the relations for the object.
        $relationsArray = $objectService->findByRelations($id);
        $relations      = array_map(static fn($relation) => $relation->getUuid(), $relationsArray);

        // Check if relations array is empty.
        if (empty($relations)) {
            // If relations is empty, set objects to an empty array
            $objects = [];
            $total   = 0;
            $config = [
                'limit' => 1,
                'offset' => 0,
                'page' => 1,
            ];
        } else {
            // Get config and fetch objects
            $config = $this->getConfig($register, $schema, $relations);

            // We specifacllly want to look outside our current definitions.
            unset($config['filters']['register'], $config['filters']['schema']);

            $objects = $objectService->findAll($config);
            // Get total count for pagination.
            $total = $objectService->count($config);
        }

        // Return paginated results.
        return new JSONResponse(
            $this->paginate(
                results: $objects,
                total: $total,
                limit: $config['limit'],
                offset: $config['offset'],
                page: $config['page']
            )
        );

    }//end used()


    /**
     * Retrieves logs for an object
     *
     * This method returns a JSON response containing the logs for a specific object.
     *
     * @param string        $id            The ID of the object to retrieve logs for
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the logs
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function logs(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the register and schema context first.
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        // Get config and fetch logs.
        $config = $this->getConfig($register, $schema);
        $logs   = $objectService->getLogs($id, $config['filters']);

        // Get total count of logs.
        $total = count($logs);

        // Return paginated results
        return new JSONResponse($this->paginate($logs, $total, $config['limit'], $config['offset'], $config['page']));

    }//end logs()


    /**
     * Lock an object
     *
     * @param int $id The ID of the object to lock
     *
     * @return JSONResponse A JSON response containing the locked object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function lock(string $id, string $register, string $schema, ObjectService $objectService): JSONResponse
    {
        // Set the schema and register to the object service.
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        $data    = $this->request->getParams();
        $process = ($data['process'] ?? null);
        // Check if duration is set in the request data.
        $duration = null;
        if (isset($data['duration']) === true) {
            $duration = (int) $data['duration'];
        }

        $object = $this->objectEntityMapper->lockObject(
            $id,
            $process,
            $duration
        );

        return new JSONResponse($object);

    }//end lock()


    /**
     * Unlock an object
     *
     * @param string $register The register slug or identifier
     * @param string $schema   The schema slug or identifier
     * @param string $id       The ID of the object to unlock
     *
     * @return JSONResponse A JSON response containing the unlocked object
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function unlock(string $register, string $schema, string $id): JSONResponse
    {
        $this->objectService->setRegister($register);
        $this->objectService->setSchema($schema);
        $this->objectService->unlock($id);
        return new JSONResponse(['message' => 'Object unlocked successfully']);

    }//end unlock()


    /**
     * Export objects to specified format
     *
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return DataDownloadResponse The exported file as a download response
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function export(string $register, string $schema, ObjectService $objectService): DataDownloadResponse
    {
        // Set the register and schema context
        $objectService->setRegister($register);
        $objectService->setSchema($schema);

        // Get filters and type from request
        $filters = $this->request->getParams();
        unset($filters['_route']);
        $type = $this->request->getParam('type', 'excel');

        // Get register and schema entities
        $registerEntity = $this->registerMapper->find($register);
        $schemaEntity = $this->schemaMapper->find($schema);

        // Handle different export types
        switch ($type) {
            case 'csv':
                $csv = $this->exportService->exportToCsv($registerEntity, $schemaEntity, $filters);
                
                // Generate filename
                $filename = sprintf(
                    '%s_%s_%s.csv',
                    $registerEntity->getSlug(),
                    $schemaEntity->getSlug(),
                    (new \DateTime())->format('Y-m-d_His')
                );
                
                return new DataDownloadResponse(
                    $csv,
                    $filename,
                    'text/csv'
                );
                
            case 'excel':
            default:
                $spreadsheet = $this->exportService->exportToExcel($registerEntity, $schemaEntity, $filters);
                
                // Create Excel writer
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                
                // Generate filename
                $filename = sprintf(
                    '%s_%s_%s.xlsx',
                    $registerEntity->getSlug(),
                    $schemaEntity->getSlug(),
                    (new \DateTime())->format('Y-m-d_His')
                );
                
                // Get Excel content
                ob_start();
                $writer->save('php://output');
                $content = ob_get_clean();
                
                return new DataDownloadResponse(
                    $content,
                    $filename,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );
        }
    }

    /**
     * Import objects into a register
     *
     * @param int $registerId The ID of the register to import into
     *
     * @return JSONResponse The result of the import operation
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function import(int $registerId): JSONResponse
    {
        try {
            // Get the uploaded file
            $uploadedFile = $this->request->getUploadedFile('file');
            if ($uploadedFile === null) {
                return new JSONResponse(['error' => 'No file uploaded'], 400);
            }

            // Get include objects parameter
            $includeObjects = $this->request->getParam('includeObjects', false);

            // Find the register
            $register = $this->registerMapper->find($registerId);

            // Import the data
            $result = $this->importService->importFromJson(
                $uploadedFile->getTempName(),
                $register,
                $includeObjects
            );

            return new JSONResponse([
                'message' => 'Import successful',
                'imported' => $result
            ]);

        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Publish an object
     *
     * This method publishes an object by setting its publication date to now or a specified date.
     *
     * @param string        $id            The ID of the object to publish
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the published object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function publish(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        try {
            // Get the publication date from request if provided
            $date = null;
            if ($this->request->getParam('date') !== null) {
                $date = new \DateTime($this->request->getParam('date'));
            }

            // Publish the object
            $object = $objectService->publish($id, $date);

            return new JSONResponse($object->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Depublish an object
     *
     * This method depublishes an object by setting its depublication date to now or a specified date.
     *
     * @param string        $id            The ID of the object to depublish
     * @param string        $register      The register slug or identifier
     * @param string        $schema        The schema slug or identifier
     * @param ObjectService $objectService The object service
     *
     * @return JSONResponse A JSON response containing the depublished object
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function depublish(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): JSONResponse {
        // Set the schema and register to the object service
        $objectService->setSchema($schema);
        $objectService->setRegister($register);

        try {
            // Get the depublication date from request if provided
            $date = null;
            if ($this->request->getParam('date') !== null) {
                $date = new \DateTime($this->request->getParam('date'));
            }

            // Depublish the object
            $object = $objectService->depublish($id, $date);

            return new JSONResponse($object->jsonSerialize());
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Download all files of an object as a ZIP archive
     *
     * This method creates a ZIP file containing all files associated with a specific object
     * and returns it as a downloadable file. The ZIP file includes all files stored in the
     * object's folder with their original names.
     *
     * @param string        $id            The identifier of the object to download files for
     * @param string        $register      The register (identifier or slug) to search within
     * @param string        $schema        The schema (identifier or slug) to search within
     * @param ObjectService $objectService The object service for handling object operations
     *
     * @return DataDownloadResponse|JSONResponse ZIP file download response or error response
     *
     * @throws ContainerExceptionInterface If there's an issue with dependency injection
     * @throws NotFoundExceptionInterface If the FileService dependency is not found
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function downloadFiles(
        string $id,
        string $register,
        string $schema,
        ObjectService $objectService
    ): DataDownloadResponse | JSONResponse {
        try {
            // Set the context for the object service
            $objectService->setRegister($register);
            $objectService->setSchema($schema);

            // Get the object to ensure it exists and we have access
            $object = $objectService->find($id);

            // Get the FileService from the container
            /** @var FileService $fileService */
            $fileService = $this->container->get(FileService::class);

            // Optional: get custom filename from query parameters
            $customFilename = $this->request->getParam('filename');

            // Create the ZIP archive
            $zipInfo = $fileService->createObjectFilesZip($object, $customFilename);

            // Read the ZIP file content
            $zipContent = file_get_contents($zipInfo['path']);
            if ($zipContent === false) {
                // Clean up temporary file
                if (file_exists($zipInfo['path'])) {
                    unlink($zipInfo['path']);
                }
                throw new \Exception('Failed to read ZIP file content');
            }

            // Clean up temporary file after reading
            if (file_exists($zipInfo['path'])) {
                unlink($zipInfo['path']);
            }

            // Return the ZIP file as a download response
            return new DataDownloadResponse(
                $zipContent,
                $zipInfo['filename'],
                $zipInfo['mimeType']
            );

        } catch (DoesNotExistException $exception) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (\Exception $exception) {
            return new JSONResponse([
                'error' => 'Failed to create ZIP file: ' . $exception->getMessage()
            ], 500);
        }

    }//end downloadFiles()

}//end class
