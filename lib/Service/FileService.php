<?php
/**
 * OpenRegister FileService.
 *
 * Service class for handling file operations in the OpenRegister application.
 * Provides functionality for managing files, folders, sharing, and versioning within
 * the NextCloud environment.
 *
 * This service provides methods for:
 * - CRUD operations on files and folders
 * - File versioning and version management
 * - File sharing and access control
 * - Tag management and attachment
 * - Object-specific file operations
 * - Audit trails and data aggregation
 *
 * @category       Service
 * @package        OCA\OpenRegister\Service
 * @author         Conduction Development Team <info@conduction.nl>
 * @copyright      2024 Conduction B.V.
 * @license        EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version        GIT: <git_id>
 * @link           https://www.OpenRegister.app
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @phpstan-type   FileArray array{
 *     id: string,
 *     name: string,
 *     path: string,
 *     type: string,
 *     mtime: int,
 *     size: int,
 *     mimetype: string,
 *     preview: string,
 *     shareTypes: array<int>,
 *     shareOwner: string|null,
 *     tags: array<string>,
 *     shareLink: string|null
 * }
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\Files_Versions\Versions\VersionManager;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Service for handling file operations in OpenRegister.
 *
 * This service provides functionalities for managing files and folders within the NextCloud environment,
 * including creation, deletion, sharing, and file updates. It integrates with NextCloud's file and
 * sharing APIs to provide seamless file management for the application.
 */
class FileService
{
    /**
     * Root folder name for all OpenRegister files.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const ROOT_FOLDER = 'Open Registers';

    /**
     * Application group name.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const APP_GROUP = 'openregister';

    /**
     * Application user name.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const APP_USER = 'OpenRegister';

    /**
     * File tag type identifier.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const FILE_TAG_TYPE = 'files';

    /**
     * Constructor for FileService.
     *
     * @param IUserSession           $userSession        The user session
     * @param IUserManager           $userManager        The user manager
     * @param LoggerInterface        $logger             The logger interface
     * @param IRootFolder           $rootFolder         The root folder interface
     * @param IManager              $shareManager       The share manager interface
     * @param IURLGenerator         $urlGenerator       URL generator service
     * @param IConfig               $config             Configuration service
     * @param RegisterMapper        $registerMapper     Register data mapper
     * @param SchemaMapper         $schemaMapper       Schema data mapper
     * @param IGroupManager         $groupManager       Group manager service
     * @param ISystemTagManager     $systemTagManager   System tag manager
     * @param ISystemTagObjectMapper $systemTagMapper    System tag object mapper
     * @param ObjectEntityMapper    $objectEntityMapper Object entity mapper
     * @param VersionManager        $versionManager     Version manager service
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder,
        private readonly IManager $shareManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IConfig $config,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IGroupManager $groupManager,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly VersionManager $versionManager
    ) {
    }//end __construct()



    /**
     * Creates a new version of a file if the object is updated.
     *
     * @param File        $file     The file to update
     * @param string|null $filename Optional new filename for the file
     *
     * @return File The updated file with a new version
     */
    public function createNewVersion(File $file, ?string $filename=null): File
    {
        $this->versionManager->createVersion(user: $this->userManager->get(self::APP_USER), file: $file);

        if ($filename !== null) {
            $file->move(targetPath: $file->getParent()->getPath().'/'.$filename);
        }

        return $file;
    }//end createNewVersion()

    /**
     * Get a specific version of a file.
     *
     * @param Node   $file    The file to get a version for
     * @param string $version The version to retrieve
     *
     * @return Node|null The requested version of the file or null if not found
     */
    public function getVersion(Node $file, string $version): ?Node
    {
        if ($file instanceof File === false) {
            return $file;
        }

        return $this->versionManager->getVersionFile($this->userManager->get(self::APP_USER), $file, $version);
    }//end getVersion()

    /**
     * Creates a folder for a Register (used for storing files of Schemas/Objects).
     *
     * @param Register|int $register The Register to create the folder for
     *
     * @throws Exception In case we can't create the folder because it is not permitted
     *
     * @return string The path to the folder
     */
    public function createRegisterFolder(Register | int $register): string
    {
        if (is_int($register) === true) {
            $register = $this->registerMapper->find($register);
        }

        $registerFolderName = $this->getRegisterFolderName($register);
        // @todo maybe we want to use ShareLink here for register->folder as well?
        $register->setFolder($this::ROOT_FOLDER."/$registerFolderName");
        $this->registerMapper->update($register);

        $folderPath = $this::ROOT_FOLDER."/$registerFolderName";
        $this->createFolder(folderPath: $folderPath);

        return $folderPath;
    }//end createRegisterFolder()

    /**
     * Get the name for the folder of a Register (used for storing files of Schemas/Objects).
     *
     * @param Register $register The Register to get the folder name for
     *
     * @return string The name the folder for this Register should have
     */
    private function getRegisterFolderName(Register $register): string
    {
        $title = $register->getTitle();

        if (str_ends_with(haystack: strtolower(rtrim($title)), needle: 'register') === true) {
            return $title;
        }

        return "$title Register";
    }//end getRegisterFolderName()

    /**
     * Creates a folder for a Schema to store files of Objects.
     *
     * This method creates a folder structure for a Schema within its parent Register's
     * folder. It ensures both the Register and Schema folders exist and are properly
     * linked in the database.
     *
     * @param Register|int $register The Register entity or its ID
     * @param Schema|int   $schema   The Schema entity or its ID
     *
     * @return string The path to the created Schema folder
     *
     * @throws Exception If folder creation fails or entities not found
     * @throws NotPermittedException If folder creation is not permitted
     * @throws NotFoundException If parent folders do not exist
     *
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return string
     */
    public function createSchemaFolder(Register | int $register, Schema | int $schema): string
    {
        // If IDs are provided, fetch the actual entities
        if (is_int($register) === true) {
            $register = $this->registerMapper->find($register);
        }

        if (is_int($schema) === true) {
            $schema = $this->schemaMapper->find($schema);
        }

        // Generate the Register folder name and update the Register
        $registerFolderName = $this->getRegisterFolderName($register);
        $register->setFolder(self::ROOT_FOLDER . '/' . $registerFolderName);
        $this->registerMapper->update($register);

        // Generate the Schema folder name and complete path
        $schemaFolderName = $this->getSchemaFolderName($schema);
        $folderPath = self::ROOT_FOLDER . '/' . $registerFolderName . '/' . $schemaFolderName;

        // Create the folder structure
        $this->createFolder(folderPath: $folderPath);

        return $folderPath;
    }

    /**
     * Get the name for the folder of a Schema.
     *
     * This method generates a folder name for storing files of objects
     * that belong to a specific Schema.
     *
     * @param Schema $schema The Schema to get the folder name for
     *
     * @return string The folder name for this Schema
     *
     * @psalm-suppress PossiblyNullReference
     * @phpstan-return string
     */
    private function getSchemaFolderName(Schema $schema): string
    {
        return $schema->getTitle();
    }

    /**
     * Creates a folder for an Object Entity.
     *
     * This method creates a folder structure for an Object Entity within its parent
     * Schema and Register folders. It ensures the complete folder hierarchy exists.
     * After creation, it sets the folder path on the ObjectEntity and persists it.
     *
     * @param ObjectEntity|string $objectEntity The Object Entity to create a folder for
     * @param Register|int|null  $register     Optional Register entity or ID
     * @param Schema|int|null    $schema       Optional Schema entity or ID
     * @param string|null        $folderPath   Optional custom folder path
     *
     * @return Node|null The created folder Node or null if creation fails
     *
     * @throws Exception If folder creation fails or entities not found
     * @throws NotPermittedException If folder creation is not permitted
     * @throws NotFoundException If parent folders do not exist
     *
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return Node|null
     */
    public function createObjectFolder(
        ObjectEntity | string $objectEntity,
        Register | int | null $register = null,
        Schema | int | null $schema = null,
        ?string $folderPath = null
    ): ?Node {
        try {
            // Use provided folder path or generate one
            $path = $folderPath ?? $this->getObjectFolderPath(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema
            );

            // Create the folder structure and get the Node
            $node = $this->createFolder(folderPath: $path);

            // If the objectEntity is an ObjectEntity instance, set the folder property to the node id (fileid) and persist
            if ($objectEntity instanceof \OCA\OpenRegister\Db\ObjectEntity && $node !== null) {
                // The node id is the fileid in the filecache table
                $objectEntity->setFolder((string) $node->getId());
                $this->objectEntityMapper->update($objectEntity);
            }

            // Return the folder node
            return $node;
        } catch (Exception $e) {
            // Log the error and return null
            $this->logger->error(
                'Failed to create object folder: {message}',
                ['message' => $e->getMessage(), 'exception' => $e]
            );
            return null;
        }
    }

    /**
     * Get the folder for an Object Entity.
     *
     * This method retrieves the folder Node for an Object Entity, creating it
     * if it doesn't exist.
     *
     * @param ObjectEntity      $objectEntity The Object Entity to get the folder for
     * @param Register|int|null $register    Optional Register entity or ID
     * @param Schema|int|null   $schema      Optional Schema entity or ID
     *
     * @return Node|null The folder Node or null if not found/created
     *
     * @throws Exception If folder retrieval fails or entities not found
     * @throws NotPermittedException If folder access is not permitted
     * @throws NotFoundException If folders do not exist
     *
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return Node|null
     */
    public function getObjectFolder(
        ObjectEntity | string $objectEntity,
        Register | int | null $register = null,
        Schema | int | null $schema = null
    ): ?Node {
        try {
            // Generate the folder path
            $path = $this->getObjectFolderPath(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema
            );

            // Try to get the existing folder
            $node = $this->getNode(path: $path);
            if ($node !== null) {
                return $node;
            }

            // Create the folder if it doesn't exist
            return $this->createObjectFolder(
                objectEntity: $objectEntity,
                register: $register,
                schema: $schema
            );

        } catch (Exception $e) {
            // Log the error and return null
            $this->logger->error(
                'Failed to get object folder: {message}',
                ['message' => $e->getMessage(), 'exception' => $e]
            );

            return null;
        }
    }

    /**
     * Get the folder path for an Object Entity.
     *
     * This method generates the complete folder path for an Object Entity,
     * including its parent Schema and Register folders.
     *
     * @param ObjectEntity      $objectEntity The Object Entity to get the path for
     * @param Register|int|null $register    Optional Register entity or ID
     * @param Schema|int|null   $schema      Optional Schema entity or ID
     *
     * @return string The complete folder path
     *
     * @throws Exception If path generation fails or entities not found
     *
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return string
     */
    private function getObjectFolderPath(
        ObjectEntity | string $objectEntity,
        Register | int | null $register = null,
        Schema | int | null $schema = null
    ): string {
        // If Register is provided as ID, fetch the entity
        if (is_int($register) === true) {
            $register = $this->registerMapper->find($register);
        }

        // If Schema is provided as ID, fetch the entity
        if (is_int($schema) === true) {
            $schema = $this->schemaMapper->find($schema);
        }

        // If Register is not provided, try to get it from the Schema
        if ($register === null && $schema !== null) {
            $register = $this->registerMapper->find($schema->getRegister());
        }

        // If Schema is not provided, try to get it from the Object Entity
        if ($schema === null && $objectEntity instanceof ObjectEntity === true) {
            $schema = $this->schemaMapper->find($objectEntity->getSchema());
            if ($register === null) {
                $register = $this->registerMapper->find($objectEntity->getRegister());
            }
        } else if ($schema === null) {
			throw new Exception(message: 'Could not deduce register and schema from object uuid');
		}

        // Generate the folder path components
        $registerFolderName = $this->getRegisterFolderName($register);
        $schemaFolderName = $this->getSchemaFolderName($schema);
        $objectFolderName = $this->getObjectFolderName($objectEntity);

        // Construct and return the complete path
        return self::ROOT_FOLDER . '/' . $registerFolderName . '/' .
               $schemaFolderName . '/' . $objectFolderName;
    }

    /**
     * Get the folder name for an Object Entity.
     *
     * This method generates a folder name for an Object Entity based on its
     * identifier or other properties.
     *
     * @param ObjectEntity $objectEntity The Object Entity to get the folder name for
     *
     * @return string The folder name
     *
     * @psalm-suppress PossiblyNullReference
     * @phpstan-return string
     */
    private function getObjectFolderName(ObjectEntity|string $objectEntity): string
    {
		if (is_string($objectEntity) === true) {
			return $objectEntity;
		}

        return $objectEntity->getUuid() ?? (string) $objectEntity->getId();
    }

    /**
     * Returns a link to the given folder path.
     *
     * @param string $folderPath The path to a folder in NextCloud
     *
     * @return string The URL to access the folder through the web interface
     */
    private function getFolderLink(string $folderPath): string
    {
        $folderPath = str_replace('%2F', '/', urlencode($folderPath));
        return $this->getCurrentDomain()."/index.php/apps/files/files?dir=$folderPath";
    }//end getFolderLink()

    /**
     * Returns a share link for the given IShare object.
     *
     * @param IShare $share An IShare object we are getting the share link for
     *
     * @return string The share link needed to get the file or folder for the given IShare object
     */
    public function getShareLink(IShare $share): string
    {
        return $this->getCurrentDomain().'/index.php/s/'.$share->getToken();
    }//end getShareLink()

    /**
     * Gets and returns the current host/domain with correct protocol.
     *
     * @return string The current http/https domain URL
     */
    private function getCurrentDomain(): string
    {
        $baseUrl = $this->urlGenerator->getBaseUrl();
        $trustedDomains = $this->config->getSystemValue('trusted_domains');

        if (isset($trustedDomains[1]) === true) {
            $baseUrl = str_replace(search: 'localhost', replace: $trustedDomains[1], subject: $baseUrl);
        }

        return $baseUrl;
    }//end getCurrentDomain()

    /**
     * Gets or creates the OpenCatalogi user for file operations.
     *
     * @throws Exception If OpenCatalogi user cannot be created
     *
     * @return IUser The OpenCatalogi user
     */
    private function getUser(): IUser
    {
        $openCatalogiUser = $this->userManager->get(self::APP_USER);

        if ($openCatalogiUser === null) {
            // Create OpenCatalogi user if it doesn't exist.
            $password = bin2hex(random_bytes(16)); // Generate random password.
            $openCatalogiUser = $this->userManager->createUser(self::APP_USER, $password);

            if ($openCatalogiUser === false) {
                throw new Exception('Failed to create OpenCatalogi user account.');
            }

            // Add user to OpenCatalogi group.
            $group = $this->groupManager->get(self::APP_GROUP);
            if ($group === null) {
                $group = $this->groupManager->createGroup(self::APP_GROUP);
            }

            // Get the current user from the session.
            $currentUser = $this->userSession->getUser();

            // Add the current user to the group.
            if ($currentUser !== null) {
                $group->addUser($currentUser);
            }

            // Add the OpenCatalogi user to the group.
            $group->addUser($openCatalogiUser);
        }

        return $openCatalogiUser;
    }//end getUser()

    /**
     * Switch to the OpenRegister user for file operations.
     *
     * This method preserves the current user context and switches to the OpenRegister
     * system user for file operations. Must be paired with switchBackToOriginalUser().
     *
     * @return IUser|null The original user that was active before switching
     *
     * @throws Exception If switching to OpenRegister user fails
     *
     * @psalm-return IUser|null
     * @phpstan-return IUser|null
     */
    private function switchToFileOperationUser(): ?IUser
    {
        // Store the current user for restoration later
        $originalUser = $this->userSession->getUser();

        try {
            // Get the OpenRegister user
            $openRegisterUser = $this->getUser();

            // Switch to the OpenRegister user context
            $this->userSession->setUser($openRegisterUser);

            $this->logger->debug(
                'Switched to OpenRegister user for file operations. Original user: {originalUser}',
                ['originalUser' => $originalUser ? $originalUser->getUID() : 'anonymous']
            );

            return $originalUser;
        } catch (Exception $e) {
            $this->logger->error('Failed to switch to file operation user: ' . $e->getMessage());
            throw new Exception('Failed to switch to file operation user: ' . $e->getMessage());
        }
    }//end switchToFileOperationUser()

    /**
     * Switch back to the original user after file operations.
     *
     * This method restores the original user context that was active before
     * switchToFileOperationUser() was called.
     *
     * @param IUser|null $originalUser The original user to restore (from switchToFileOperationUser)
     *
     * @return void
     *
     * @psalm-return void
     * @phpstan-return void
     */
    private function switchBackToOriginalUser(?IUser $originalUser): void
    {
        try {
            if ($originalUser !== null) {
                $this->userSession->setUser($originalUser);
                $this->logger->debug(
                    'Switched back to original user: {originalUser}',
                    ['originalUser' => $originalUser->getUID()]
                );
            } else {
                // If there was no original user (anonymous session), clear the session
                $this->userSession->setUser(null);
                $this->logger->debug('Switched back to anonymous session');
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to switch back to original user: ' . $e->getMessage());
            // Don't throw here as this could mask the original exception from file operations
        }
    }//end switchBackToOriginalUser()

    /**
     * Execute a callable with proper user context switching.
     *
     * This method handles the complete user switching workflow:
     * 1. Switch to OpenRegister user
     * 2. Execute the provided callable
     * 3. Switch back to original user (even if callable throws)
     *
     * @param callable $operation The operation to execute under OpenRegister user context
     *
     * @return mixed The return value from the callable
     *
     * @throws Exception If user switching fails or the callable throws
     *
     * @todo Optimaly speaking, we should not need to use this method at all. So its here for a "now" solution. but should be removed for other solutions in the future.
     *
     * @psalm-template T
     * @psalm-param callable(): T $operation
     * @psalm-return T
     * @phpstan-template T
     * @phpstan-param callable(): T $operation
     * @phpstan-return T
     */
    private function executeWithFileUserContext(callable $operation): mixed
    {
        $originalUser = null;

        try {
            // Switch to file operation user
            $originalUser = $this->switchToFileOperationUser();

            // Execute the operation
            $result = $operation();

            // Switch back to original user
            $this->switchBackToOriginalUser($originalUser);

            return $result;
        } catch (Exception $e) {
            // Ensure we always switch back, even if operation fails
            $this->switchBackToOriginalUser($originalUser);
            throw $e;
        }
    }//end executeWithFileUserContext()

    /**
     * Gets a NextCloud Node object for the given file or folder path.
     *
     * @param string $path The path to get the Node object for
     *
     * @return Node|null The Node object if found, null otherwise
     */
    public function getNode(string $path): ?Node
    {
        return $this->executeWithFileUserContext(function () use ($path): ?Node {
            try {
                $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());
                return $userFolder->get(path: $path);
            } catch (NotFoundException | NotPermittedException $e) {
                $this->logger->error(message: $e->getMessage());
                return null;
            }
        });
    }//end getNode()

    /**
     * Formats a single Node file into a metadata array.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param Node $file The Node file to format
     *
     * @return array<string, mixed> The formatted file metadata array
     */
    public function formatFile(Node $file): array
    {
        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->findShares($file);

        // Get base metadata array.
        $metadata = [
            'id'          => $file->getId(),
            'path'        => $file->getPath(),
            'title'       => $file->getName(),
            'accessUrl'   => count($shares) > 0 ? $this->getShareLink($shares[0]) : null,
            'downloadUrl' => count($shares) > 0 ? $this->getShareLink($shares[0]).'/download' : null,
            'type'        => $file->getMimetype(),
            'extension'   => $file->getExtension(),
            'size'        => $file->getSize(),
            'hash'        => $file->getEtag(),
            'published'   => count($shares) > 0 ? $shares[0]->getShareTime()->format('c') : null,
            'modified'    => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
            'labels'      => $this->getFileTags(fileId: $file->getId()),
        ];

        // Process labels that contain ':' to add as separate metadata fields.
        // Exclude labels starting with 'object:' as they are internal system labels.
        $remainingLabels = [];
        foreach ($metadata['labels'] as $label) {
            // Skip internal object labels - these should not be exposed in the API
            if (str_starts_with($label, 'object:')) {
                continue;
            }

            if (strpos($label, ':') !== false) {
                list($key, $value) = explode(':', $label, 2);
                $key = trim($key);
                $value = trim($value);

                // Skip if key exists in base metadata.
                if (isset($metadata[$key])) {
                    $remainingLabels[] = $label;
                    continue;
                }

                // If key already exists as array, append value.
                if (isset($metadata[$key]) && is_array($metadata[$key]) === true) {
                    $metadata[$key][] = $value;
                } else if (isset($metadata[$key])) {
                    // If key exists but not as array, convert to array with both values.
                    $metadata[$key] = [$metadata[$key], $value];
                } else {
                    // If key doesn't exist, create new entry.
                    $metadata[$key] = $value;
                }
            } else {
                $remainingLabels[] = $label;
            }
        }

        // Update labels array to only contain non-processed, non-internal labels.
        $metadata['labels'] = $remainingLabels;

        return $metadata;
    }//end formatFile()

    /**
     * Formats an array of Node files into an array of metadata arrays.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param Node[] $files         Array of Node files to format
     * @param array  $requestParams Optional request parameters including filters:
     *     _hasLabels: bool,
     *     _noLabels: bool,
     *     labels: string|array,
     *     extension: string,
     *     extensions: array,
     *     minSize: int,
     *     maxSize: int,
     *     title: string,
     *     search: string,
     *     limit: int,
     *     offset: int,
     *     order: string|array,
     *     page: int,
     *     extend: string|array
     *
     * @throws InvalidPathException
     * @throws NotFoundException
     *
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int
     * } Array of formatted file metadata arrays
     */
    public function formatFiles(array $files, ?array $requestParams=[]): array
    {
        // Extract pagination parameters
        $limit = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
        $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? 0;
        $order = $requestParams['order'] ?? $requestParams['_order'] ?? [];
        $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
        $page = $requestParams['page'] ?? $requestParams['_page'] ?? null;
        $search = $requestParams['_search'] ?? null;

        if ($page !== null && isset($limit) === true) {
            $page = (int) $page;
            $offset = $limit * ($page - 1);
        }

        // Ensure order and extend are arrays
        if (is_string($order) === true) {
            $order = array_map('trim', explode(',', $order));
        }
        if (is_string($extend) === true) {
            $extend = array_map('trim', explode(',', $extend));
        }

        // Extract filter parameters
        $filters = $this->extractFilterParameters($requestParams);

        // Format ALL files first (before filtering and pagination)
        $formattedFiles = [];
        foreach ($files as $file) {
            $formattedFiles[] = $this->formatFile($file);
        }

        // Apply filters to formatted files
        $filteredFiles = $this->applyFileFilters($formattedFiles, $filters);

        // Count total after filtering but before pagination
        $totalFiltered = count($filteredFiles);

        // Apply pagination to filtered results
        $paginatedFiles = array_slice($filteredFiles, $offset, $limit);

        // Calculate pages based on filtered total
        $pages = $limit !== null ? ceil($totalFiltered / $limit) : 1;

        return [
            'results' => $paginatedFiles,
            'total'   => $totalFiltered,
            'page'    => $page ?? 1,
            'pages'   => $pages,
        ];
    }//end formatFiles()

    /**
     * Extract and normalize filter parameters from request parameters.
     *
     * This method extracts filter-specific parameters from the request, excluding
     * pagination and other control parameters. It normalizes string parameters
     * to arrays where appropriate for consistent filtering logic.
     *
     * @param array $requestParams The request parameters array
     *
     * @return array{
     *     _hasLabels?: bool,
     *     _noLabels?: bool,
     *     labels?: array<string>,
     *     extension?: string,
     *     extensions?: array<string>,
     *     minSize?: int,
     *     maxSize?: int,
     *     title?: string,
     *     search?: string
     * } Normalized filter parameters
     *
     * @psalm-param array<string, mixed> $requestParams
     * @phpstan-param array<string, mixed> $requestParams
     */
    private function extractFilterParameters(array $requestParams): array
    {
        $filters = [];

        // Labels filtering (business logic filters prefixed with underscore)
        if (isset($requestParams['_hasLabels'])) {
            $filters['_hasLabels'] = (bool) $requestParams['_hasLabels'];
        }

        if (isset($requestParams['_noLabels'])) {
            $filters['_noLabels'] = (bool) $requestParams['_noLabels'];
        }

        if (isset($requestParams['labels'])) {
            $labels = $requestParams['labels'];
            if (is_string($labels)) {
                $filters['labels'] = array_map('trim', explode(',', $labels));
            } else if (is_array($labels)) {
                $filters['labels'] = $labels;
            }
        }

        // Extension filtering
        if (isset($requestParams['extension'])) {
            $filters['extension'] = trim($requestParams['extension']);
        }

        if (isset($requestParams['extensions'])) {
            $extensions = $requestParams['extensions'];
            if (is_string($extensions)) {
                $filters['extensions'] = array_map('trim', explode(',', $extensions));
            } else if (is_array($extensions)) {
                $filters['extensions'] = $extensions;
            }
        }

        // Size filtering
        if (isset($requestParams['minSize'])) {
            $filters['minSize'] = (int) $requestParams['minSize'];
        }

        if (isset($requestParams['maxSize'])) {
            $filters['maxSize'] = (int) $requestParams['maxSize'];
        }

        // Title/search filtering
        if (isset($requestParams['title'])) {
            $filters['title'] = trim($requestParams['title']);
        }

        if (isset($requestParams['search']) || isset($requestParams['_search'])) {
            $filters['search'] = trim($requestParams['search'] ?? $requestParams['_search']);
        }

        return $filters;
    }//end extractFilterParameters()

    /**
     * Apply filters to an array of formatted file metadata.
     *
     * This method applies various filters to the formatted file metadata based on
     * the provided filter parameters. Filters are applied in sequence and files
     * must match ALL specified criteria to be included in the results.
     *
     * @param array $formattedFiles Array of formatted file metadata
     * @param array $filters        Filter parameters to apply
     *
     * @return array Filtered array of file metadata
     *
     * @psalm-param array<int, array<string, mixed>> $formattedFiles
     * @phpstan-param array<int, array<string, mixed>> $formattedFiles
     * @psalm-param array<string, mixed> $filters
     * @phpstan-param array<string, mixed> $filters
     * @psalm-return array<int, array<string, mixed>>
     * @phpstan-return array<int, array<string, mixed>>
     */
    private function applyFileFilters(array $formattedFiles, array $filters): array
    {
        if (empty($filters)) {
            return $formattedFiles;
        }

        return array_filter($formattedFiles, function (array $file) use ($filters): bool {
            // Filter by label presence (business logic filter)
            if (isset($filters['_hasLabels'])) {
                $hasLabels = !empty($file['labels']);
                if ($filters['_hasLabels'] !== $hasLabels) {
                    return false;
                }
            }

            // Filter for files without labels (business logic filter)
            if (isset($filters['_noLabels']) && $filters['_noLabels'] === true) {
                $hasLabels = !empty($file['labels']);
                if ($hasLabels) {
                    return false;
                }
            }

            // Filter by specific labels
            if (isset($filters['labels']) && !empty($filters['labels'])) {
                $fileLabels = $file['labels'] ?? [];
                $hasMatchingLabel = false;

                foreach ($filters['labels'] as $requiredLabel) {
                    if (in_array($requiredLabel, $fileLabels, true)) {
                        $hasMatchingLabel = true;
                        break;
                    }
                }

                if (!$hasMatchingLabel) {
                    return false;
                }
            }

            // Filter by single extension
            if (isset($filters['extension'])) {
                $fileExtension = $file['extension'] ?? '';
                if (strcasecmp($fileExtension, $filters['extension']) !== 0) {
                    return false;
                }
            }

            // Filter by multiple extensions
            if (isset($filters['extensions']) && !empty($filters['extensions'])) {
                $fileExtension = $file['extension'] ?? '';
                $hasMatchingExtension = false;

                foreach ($filters['extensions'] as $allowedExtension) {
                    if (strcasecmp($fileExtension, $allowedExtension) === 0) {
                        $hasMatchingExtension = true;
                        break;
                    }
                }

                if (!$hasMatchingExtension) {
                    return false;
                }
            }

            // Filter by file size range
            if (isset($filters['minSize'])) {
                $fileSize = $file['size'] ?? 0;
                if ($fileSize < $filters['minSize']) {
                    return false;
                }
            }

            if (isset($filters['maxSize'])) {
                $fileSize = $file['size'] ?? 0;
                if ($fileSize > $filters['maxSize']) {
                    return false;
                }
            }

            // Filter by title/filename content
            if (isset($filters['title']) && !empty($filters['title'])) {
                $fileTitle = $file['title'] ?? '';
                if (stripos($fileTitle, $filters['title']) === false) {
                    return false;
                }
            }

            // Filter by search term (searches in title)
            if (isset($filters['search']) && !empty($filters['search'])) {
                $fileTitle = $file['title'] ?? '';
                if (stripos($fileTitle, $filters['search']) === false) {
                    return false;
                }
            }

            // File passed all filters
            return true;
        });
    }//end applyFileFilters()

    /**
     * Get the tags associated with a file.
     *
     * @param string $fileId The ID of the file
     *
     * @return array<int, string> The list of tags associated with the file
     */
    private function getFileTags(string $fileId): array
    {
        $tagIds = $this->systemTagMapper->getTagIdsForObjects(
            objIds: [$fileId],
            objectType: $this::FILE_TAG_TYPE
        );
        if (isset($tagIds[$fileId]) === false || empty($tagIds[$fileId]) === true) {
            return [];
        }

        $tags = $this->systemTagManager->getTagsByIds(tagIds: $tagIds[$fileId]);

        $tagNames = array_map(static function ($tag) {
            return $tag->getName();
        }, $tags);

        return array_values($tagNames);
    }//end getFileTags()

    /**
     * Finds shares associated with a file or folder.
     *
     * @param Node $file      The Node file or folder to find shares for
     * @param int  $shareType The type of share to look for (default: 3 for public link)
     *
     * @return IShare[] Array of shares associated with the file
     */
    public function findShares(Node $file, int $shareType=3): array
    {
        // Get the current user.
        $currentUser = $this->userSession->getUser();
        $userId = $currentUser ? $currentUser->getUID() : 'Guest';

        return $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
    }//end findShares()

    /**
     * Try to find a IShare object with given $path & $shareType.
     *
     * @param string   $path      The path to a file we are trying to find a IShare object for
     * @param int|null $shareType The shareType of the share we are trying to find (default: 3 for public link)
     *
     * @return IShare|null An IShare object if found, null otherwise
     */
    public function findShare(string $path, ?int $shareType=3): ?IShare
    {
        $path = trim(string: $path, characters: '/');
        $userId = $this->getUser()->getUID();

        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
        } catch (NotPermittedException) {
            $this->logger->error("Can't find share for $path because user (folder) for user $userId couldn't be found.");
            return null;
        }

        try {
            // Note: if we ever want to find shares for folders instead of files, this should work for folders as well?
            $file = $userFolder->get(path: $path);
        } catch (NotFoundException $e) {
            $this->logger->error("Can't find share for $path because file doesn't exist.");
            return null;
        }

        if ($file instanceof File) {
            $shares = $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
            if (count($shares) > 0) {
                return $shares[0];
            }
        }

        return null;
    }//end findShare()

    /**
     * Creates a IShare object using the $shareData array data.
     *
     * @param array{
     *     path: string,
     *     file?: File,
     *     nodeId?: int,
     *     nodeType?: string,
     *     shareType: int,
     *     permissions?: int,
     *     sharedWith?: string
     * } $shareData The data to create a IShare with
     *
     * @throws Exception If creating the share fails
     *
     * @return IShare The Created IShare object
     */
    private function createShare(array $shareData): IShare
    {
        $userId = $this->getUser()->getUID();

        // Create a new share.
        $share = $this->shareManager->newShare();
        $share->setTarget(target: '/'.$shareData['path']);
        if (empty($shareData['file']) === false) {
            $share->setNodeId(fileId: $shareData['file']->getId());
        }
        if (empty($shareData['nodeId']) === false) {
            $share->setNodeId(fileId: $shareData['nodeId']);
        }
        $share->setNodeType(type: $shareData['nodeType'] ?? 'file');
        $share->setShareType(shareType: $shareData['shareType']);
        if ($shareData['permissions'] !== null) {
            $share->setPermissions(permissions: $shareData['permissions']);
        }
        $share->setSharedBy(sharedBy: $userId);
        $share->setShareOwner(shareOwner: $userId);
        $share->setShareTime(shareTime: new DateTime());
        if (empty($shareData['sharedWith']) === false) {
            $share->setSharedWith(sharedWith: $shareData['sharedWith']);
        }
        $share->setStatus(status: $share::STATUS_ACCEPTED);

        return $this->shareManager->createShare(share: $share);
    }//end createShare()

    /**
     * Creates and returns a share link for a file (or folder).
     *
     * See https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html#create-a-new-share.
     *
     * @param string   $path        Path (from root) to the file/folder which should be shared
     * @param int|null $shareType   The share type (0=user, 1=group, 3=public link, 4=email, etc.)
     * @param int|null $permissions Permissions (1=read, 2=update, 4=create, 8=delete, 16=share, 31=all)
     *
     * @throws Exception If creating the share link fails
     *
     * @return string The share link
     */
    public function createShareLink(string $path, ?int $shareType=3, ?int $permissions=null): string
    {
        return $this->executeWithFileUserContext(function () use ($path, $shareType, $permissions): string {
            $path = trim(string: $path, characters: '/');
            if ($permissions === null) {
                $permissions = 31;
                if ($shareType === 3) {
                    $permissions = 1;
                }
            }

            $userId = $this->getUser()->getUID();

            try {
                $userFolder = $this->rootFolder->getUserFolder(userId: $userId);
            } catch (NotPermittedException) {
                $this->logger->error("Can't create share link for $path because user (folder) for user $userId couldn't be found.");
                return "User (folder) couldn't be found.";
            }

            try {
                $file = $this->rootFolder->get($path);
                // $file = $userFolder->get(path: $path);
            } catch (NotFoundException $e) {
                $this->logger->error("Can't create share link for $path because file doesn't exist.");
                return 'File not found at '.$path;
            }

            try {
                $share = $this->createShare([
                    'path'        => $path,
                    'file'        => $file,
                    'shareType'   => $shareType,
                    'permissions' => $permissions,
                ]);
                return $this->getShareLink($share);
            } catch (Exception $exception) {
                $this->logger->error("Can't create share link for $path: ".$exception->getMessage());
                throw new Exception('Can\'t create share link.');
            }
        });
    }//end createShareLink()

    /**
     * Deletes all share links for a file or folder.
     *
     * @param Node $file The file or folder whose shares should be deleted
     *
     * @throws Exception If the shares cannot be deleted
     *
     * @return Node The file with shares deleted
     */
    public function deleteShareLinks(Node $file): Node
    {
        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->findShares($file);

        foreach ($shares as $share) {
            try {
                $this->shareManager->deleteShare($share);
                $this->logger->info("Successfully deleted share for path: {$share->getNode()->getPath()}.");
            } catch (Exception $e) {
                $this->logger->error("Failed to delete share for path {$share->getNode()->getPath()}: ".$e->getMessage());
                throw new Exception("Failed to delete share for path {$share->getNode()->getPath()}: ".$e->getMessage());
            }
        }

        return $file;
    }//end deleteShareLinks()

    /**
     * Creates a new folder in NextCloud, unless it already exists.
     *
     * @param string $folderPath Path (from root) to where you want to create a folder, include the name of the folder
     *
     * @throws Exception If creating the folder is not permitted
     *
     * @return Node|null The Node object for the folder (existing or newly created), or null on failure
     */
    public function createFolder(string $folderPath): ?Node
    {
        return $this->executeWithFileUserContext(function () use ($folderPath): ?Node {
            $folderPath = trim(string: $folderPath, characters: '/');

            // Get the current user.
            $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

            // Check if folder exists and if not create it.
            try {
                // First, check if the root folder exists, and if not, create it and share it with the openregister group.
                try {
                    $userFolder->get(self::ROOT_FOLDER);
                } catch (NotFoundException) {
                    $rootFolder = $userFolder->newFolder(self::ROOT_FOLDER);

                    if ($this->groupManager->groupExists(self::APP_GROUP) === false) {
                        $this->groupManager->createGroup(self::APP_GROUP);
                    }

                    $this->createShare([
                        'path'        => self::ROOT_FOLDER,
                        'nodeId'      => $rootFolder->getId(),
                        'nodeType'    => $rootFolder->getType() === 'file' ? $rootFolder->getType() : 'folder',
                        'shareType'   => 1,
                        'permissions' => 31,
                        'sharedWith'  => self::APP_GROUP,
                    ]);
                }

                try {
                    // Try to get the folder if it already exists
                    $node = $userFolder->get(path: $folderPath);
                    $this->logger->info("This folder already exists: $folderPath");
                    return $node;
                } catch (NotFoundException) {
                    // Folder does not exist, create it
                    $node = $userFolder->newFolder(path: $folderPath);
                    $this->logger->info("Created folder: $folderPath");
                    return $node;
                }
            } catch (NotPermittedException $e) {
                $this->logger->error("Can't create folder $folderPath: ".$e->getMessage());
                throw new Exception("Can't create folder $folderPath");
            }
        });
    }//end createFolder()

    /**
     * Overwrites an existing file in NextCloud.
     *
     * This method updates the content and/or tags of an existing file. When updating tags,
     * it preserves any existing 'object:' tags while replacing other user-defined tags.
     *
     * @param string             $filePath The path (from root) where to save the file, including filename and extension
     * @param mixed              $content  Optional content of the file. If null, only metadata like tags will be updated
     * @param array              $tags     Optional array of tags to attach to the file (excluding object tags which are preserved)
     * @param ObjectEntity|null  $object   Optional object entity to search in object folder first
     *
     * @throws Exception If the file doesn't exist or if file operations fail
     *
     * @return File The updated file
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param array<int, string> $tags
     */
    public function updateFile(string $filePath, mixed $content=null, array $tags=[], ?ObjectEntity $object = null): File
    {
        return $this->executeWithFileUserContext(function () use ($filePath, $content, $tags, $object): File {
            // Debug logging - original file path
            $originalFilePath = $filePath;
            $this->logger->info("updateFile: Original file path received: '$originalFilePath'");

            // Clean and decode the file path
            $filePath = trim(string: $filePath, characters: '/');
            $this->logger->info("updateFile: After trim: '$filePath'");

            $filePath = urldecode($filePath);
            $this->logger->info("updateFile: After urldecode: '$filePath'");

            $file = null;

            // If object is provided, try to find the file in the object folder first
            if ($object !== null) {
                try {
                    $objectFolder = $this->getObjectFolder(
                        objectEntity: $object,
                        register: $object->getRegister(),
                        schema: $object->getSchema()
                    );

                    if ($objectFolder !== null) {
                        $this->logger->info("updateFile: Object folder path: " . $objectFolder->getPath());

                        // Try to get the file from object folder
                        try {
                            $file = $objectFolder->get($filePath);
                            $this->logger->info("updateFile: Found file in object folder: " . $file->getName());
                        } catch (NotFoundException) {
                            $this->logger->warning("updateFile: File $filePath not found in object folder.");
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error("updateFile: Error accessing object folder: " . $e->getMessage());
                }
            }

            // If object wasn't provided or file wasn't found in object folder, try user folder
            if ($file === null) {
                $this->logger->info("updateFile: Trying user folder approach...");
                try {
                    $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());
                    $file = $userFolder->get(path: $filePath);
                    $this->logger->info("updateFile: Found file in user folder at path: $filePath");
                } catch (NotFoundException $e) {
                    $this->logger->error("updateFile: File $filePath not found in user folder either.");
                    throw new Exception("File $filePath does not exist");
                } catch (NotPermittedException | InvalidPathException $e) {
                    $this->logger->error("updateFile: Can't access file $filePath: ".$e->getMessage());
                    throw new Exception("Can't access file $filePath: ".$e->getMessage());
                }
            }

            // Update the file content if provided
            if ($content !== null) {
                try {
                    $file->putContent(data: $content);
                    $this->logger->info("updateFile: Successfully updated file content: " . $file->getName());
                } catch (NotPermittedException $e) {
                    $this->logger->error("updateFile: Can't write content to file: ".$e->getMessage());
                    throw new Exception("Can't write content to file: ".$e->getMessage());
                }
            }

            // Update tags if provided
            if (empty($tags) === false) {
                // Get existing object tags to preserve them
                $existingTags = $this->getFileTags(fileId: $file->getId());
                $objectTags = array_filter($existingTags, static function (string $tag): bool {
                    return str_starts_with($tag, 'object:');
                });

                // Combine object tags with new tags, avoiding duplicates
                $allTags = array_unique(array_merge($objectTags, $tags));

                $this->attachTagsToFile(fileId: $file->getId(), tags: $allTags);
                $this->logger->info("updateFile: Successfully updated file tags: " . $file->getName());
            }

            return $file;
        });
    }//end updateFile()

    /**
     * Constructs a file path for a specific object.
     *
     * @param string|ObjectEntity $object   The object entity or object UUID
     * @param string             $filePath The relative file path within the object folder
     *
     * @return string The complete file path
     */
    public function getObjectFilePath(string | ObjectEntity $object, string $filePath): string
    {
        return $object->getFolder().'/'.$filePath;
    }//end getObjectFilePath()

    /**
     * Deletes a file from NextCloud.
     *
     * This method can accept either a file path string or a Node object for deletion.
     * When a Node object is provided, it will be deleted directly. When a string path
     * is provided, the file will be located first and then deleted.
     *
     * If an ObjectEntity is provided, the method will also update the object's files
     * array to remove the reference to the deleted file and save the updated object.
     *
     * @param Node|string        $file   The file Node object or path (from root) to the file you want to delete
     * @param ObjectEntity|null  $object Optional object entity to update the files array for
     *
     * @throws Exception If deleting the file is not permitted or file operations fail
     *
     * @return bool True if successful, false if the file didn't exist
     *
     * @psalm-param Node|string $file
     * @phpstan-param Node|string $file
     * @psalm-param ObjectEntity|null $object
     * @phpstan-param ObjectEntity|null $object
     */
    public function deleteFile(Node | string $file, ?ObjectEntity $object = null): bool
    {
        return $this->executeWithFileUserContext(function () use ($file, $object): bool {
            try {
                $deletedFilePath = null;
                $fileDeleted = false;

                // If we received a Node object, delete it directly
                if ($file instanceof Node) {
                    $deletedFilePath = $file->getPath();
                    $fileName = $file->getName();
                    $this->logger->info("Deleting file node: $fileName");
                    $file->delete();
                    $fileDeleted = true;
                } else {
                    // If we received a string path, locate the file first then delete it
                    // Clean and decode the file path
                    $originalFilePath = $file;
                    $filePath = trim(string: $file, characters: '/');
                    $filePath = urldecode($filePath);
                    $deletedFilePath = $filePath;

                    $this->logger->info("deleteFile: Original file path received: '$originalFilePath'");
                    $this->logger->info("deleteFile: After trim and decode: '$filePath'");

                    // If object is provided, try to find the file in the object folder first
                    if ($object !== null) {
                        try {
                            $objectFolder = $this->getObjectFolder(
                                objectEntity: $object,
                                register: $object->getRegister(),
                                schema: $object->getSchema()
                            );

                            if ($objectFolder !== null) {
                                $this->logger->info("deleteFile: Object folder path: " . $objectFolder->getPath());

                                // Try to get the file from object folder
                                try {
                                    $fileNode = $objectFolder->get($filePath);
                                    $this->logger->info("deleteFile: Found file in object folder: " . $fileNode->getName());
                                    $fileNode->delete();
                                    $fileDeleted = true;
                                } catch (NotFoundException) {
                                    $this->logger->warning("deleteFile: File $filePath not found in object folder.");
                                    $fileDeleted = false;
                                }
                            }
                        } catch (Exception $e) {
                            $this->logger->error("deleteFile: Error accessing object folder: " . $e->getMessage());
                        }
                    }

                    // If object wasn't provided or file wasn't found in object folder, try user folder
                    if ($fileDeleted === false) {
                        $this->logger->info("deleteFile: Trying user folder approach...");
                        $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

                        // Check if file exists and delete it if it does.
                        try {
                            $fileNode = $userFolder->get(path: $filePath);
                            $this->logger->info("deleteFile: Found file in user folder at path: $filePath");
                            $fileNode->delete();
                            $fileDeleted = true;
                        } catch (NotFoundException) {
                            // File does not exist.
                            $this->logger->warning("deleteFile: File $filePath does not exist in user folder either.");
                            $fileDeleted = false;
                        }
                    }
                }

                // If the file was successfully deleted and an object was provided, update the object's files array
                if ($fileDeleted && $object !== null && $deletedFilePath !== null) {
                    $this->updateObjectFilesArray($object, $deletedFilePath);
                }

                return $fileDeleted;

            } catch (NotPermittedException | InvalidPathException $e) {
                $filePath = $file instanceof Node ? $file->getPath() : $file;
                $this->logger->error("Can't delete file $filePath: ".$e->getMessage());
                throw new Exception("Can't delete file $filePath: ".$e->getMessage());
            } catch (Exception $e) {
                $filePath = $file instanceof Node ? $file->getPath() : $file;
                $this->logger->error("Can't delete file $filePath: ".$e->getMessage());
                throw new Exception("Can't delete file $filePath: ".$e->getMessage());
            }
        });
    }//end deleteFile()

    /**
     * Update an object's files array by removing a deleted file reference.
     *
     * This method searches through the object's files array and removes any entries
     * that reference the deleted file path. It handles both absolute and relative paths.
     *
     * @param ObjectEntity $object           The object entity to update
     * @param string       $deletedFilePath  The path of the deleted file
     *
     * @return void
     *
     * @throws Exception If updating the object fails
     *
     * @psalm-return void
     * @phpstan-return void
     */
    private function updateObjectFilesArray(ObjectEntity $object, string $deletedFilePath): void
    {
        try {
            // Get the current files array from the object
            $objectFiles = $object->getFiles() ?? [];

            if (empty($objectFiles)) {
                $this->logger->debug("Object {$object->getId()} has no files array to update");
                return;
            }

            $originalCount = count($objectFiles);
            $updatedFiles = [];

            // Extract just the filename from the deleted file path for comparison
            $deletedFileName = basename($deletedFilePath);

            // Filter out any files that match the deleted file
            foreach ($objectFiles as $fileEntry) {
                $shouldKeep = true;

                // Handle different possible structures of file entries
                if (is_array($fileEntry)) {
                    // Check various possible path fields in the file entry
                    $pathFields = ['path', 'title', 'name', 'filename', 'accessUrl', 'downloadUrl'];

                    foreach ($pathFields as $field) {
                        if (isset($fileEntry[$field])) {
                            $entryPath = $fileEntry[$field];
                            $entryFileName = basename($entryPath);

                            // Check if this entry references the deleted file
                            if ($entryPath === $deletedFilePath ||
                                $entryFileName === $deletedFileName ||
                                str_ends_with($entryPath, $deletedFilePath)) {
                                $shouldKeep = false;
                                $this->logger->info("Removing file entry from object {$object->getId()}: $entryPath");
                                break;
                            }
                        }
                    }
                } else if (is_string($fileEntry)) {
                    // Handle simple string entries
                    $entryFileName = basename($fileEntry);
                    if ($fileEntry === $deletedFilePath ||
                        $entryFileName === $deletedFileName ||
                        str_ends_with($fileEntry, $deletedFilePath)) {
                        $shouldKeep = false;
                        $this->logger->info("Removing file entry from object {$object->getId()}: $fileEntry");
                    }
                }

                if ($shouldKeep) {
                    $updatedFiles[] = $fileEntry;
                }
            }

            // Only update the object if files were actually removed
            if (count($updatedFiles) < $originalCount) {
                $removedCount = $originalCount - count($updatedFiles);
                $this->logger->info("Removed $removedCount file reference(s) from object {$object->getId()}");

//                $object->setFiles($updatedFiles);
//                $this->objectEntityMapper->update($object);
            } else {
                $this->logger->debug("No file references found to remove from object {$object->getId()}");
            }

        } catch (Exception $e) {
            $this->logger->error("Failed to update object files array for object {$object->getId()}: " . $e->getMessage());
            throw new Exception("Failed to update object files array: " . $e->getMessage());
        }
    }//end updateObjectFilesArray()

    /**
     * Attach tags to a file.
     *
     * @param string $fileId The file ID
     * @param array  $tags   Tags to associate with the file
     *
     * @return void
     */
    private function attachTagsToFile(string $fileId, array $tags=[]): void
    {
        // Get all existing tags for the file and convert to array of just the IDs.
        $oldTagIds = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: $this::FILE_TAG_TYPE);
        if (isset($oldTagIds[$fileId]) === false || empty($oldTagIds[$fileId]) === true) {
            $oldTagIds = [];
        } else {
            $oldTagIds = $oldTagIds[$fileId];
        }

        // Create new tags if they don't exist.
        $newTagIds = [];
        foreach ($tags as $tagName) {
            // Skip empty tag names.
            if (empty($tagName)) {
                continue;
            }

            try {
				$tag = $this->systemTagManager->getTag(tagName: $tagName, userVisible: true, userAssignable: true);
			} catch (Exception $exception) {
                $tag = $this->systemTagManager->createTag(tagName: $tagName, userVisible: true, userAssignable: true);
            }

            $newTagIds[] = $tag->getId();
        }

        // Only assign new tags if we have any.
        if (empty($newTagIds) === false) {
				$newTagIds = array_unique($newTagIds);
				$this->systemTagMapper->assignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $newTagIds);
        }

        // Find tags that exist in old tags but not in new tags (tags to be removed).
        $tagsToRemove = array_diff($oldTagIds ?? [], $newTagIds ?? []);
        // Remove any keys with value 0 from tags to remove array.
        $tagsToRemove = array_filter($tagsToRemove, function ($value) {
            return $value !== 0;
        });

        // Remove old tags that aren't in new tags.
        if (empty($tagsToRemove) === false) {
            $this->systemTagMapper->unassignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $tagsToRemove);
        }

        // @todo Let's check if there are now existing tags without files (orphans) that need to be deleted.
    }//end attachTagsToFile()

    /**
     * Generate the object tag for a given ObjectEntity.
     *
     * This method creates a standardized object tag that links a file to its parent object.
     * The tag format is 'object:' followed by the object's UUID or ID.
     *
     * @param ObjectEntity $objectEntity The object entity to generate the tag for
     *
     * @return string The object tag in format 'object:uuid' or 'object:id'
     *
     * @psalm-return string
     * @phpstan-return string
     */
    private function generateObjectTag(ObjectEntity|string $objectEntity): string
    {
		if($objectEntity instanceof ObjectEntity === false) {
			return 'object:'.$objectEntity;
		}

        // Use UUID if available, otherwise fall back to the numeric ID
        $identifier = $objectEntity->getUuid() ?? (string) $objectEntity->getId();
        return 'object:' . $identifier;
    }//end generateObjectTag()

    /**
     * Adds a new file to an object's folder with the OpenCatalogi user as owner.
     *
     * This method automatically adds an 'object:' tag containing the object's UUID
     * in addition to any user-provided tags.
     *
     * @param ObjectEntity $objectEntity The object entity to add the file to
     * @param string       $fileName     The name of the file to create
     * @param string       $content      The content to write to the file
     * @param bool         $share        Whether to create a share link for the file
     * @param array        $tags         Optional array of tags to attach to the file
     *
     * @throws NotPermittedException If file creation fails due to permissions
     * @throws Exception If file creation fails for other reasons
     *
     * @return File The created file
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param array<int, string> $tags
     */
    public function addFile(ObjectEntity | string $objectEntity, string $fileName, string $content, bool $share = false, array $tags = [], int | Schema | null $schema = null, int | Register | null $register = null): File
    {
        return $this->executeWithFileUserContext(function () use ($objectEntity, $fileName, $content, $share, $tags, $register, $schema): File {
			try {
				// Create new file in the folder
				$folder = $this->getObjectFolder(
					objectEntity: $objectEntity,
					register: $objectEntity instanceof ObjectEntity === true ? $objectEntity->getRegister() : $register,
					schema: $objectEntity instanceof ObjectEntity === true ? $objectEntity->getSchema() : $schema
				);

                // Check if the content is base64 encoded and decode it if necessary
                if (base64_encode(base64_decode($content, true)) === $content) {
                    $content = base64_decode($content);
                }

                // Check if the file name is empty
                if (empty($fileName) === true) {
                    throw new Exception("Failed to create file because no filename has been provided for object " . $objectEntity->getId());
                }

                /**
                 * @var File $file
                 */
                $file = $folder->newFile($fileName);

                // Write content to the file
                $file->putContent($content);

                // Create a share link for the file if requested
                if ($share === true) {
                    $this->createShareLink(path: $file->getPath());
                }

                // Automatically add object tag with the object's UUID
                $objectTag = $this->generateObjectTag($objectEntity);
                $allTags = array_merge([$objectTag], $tags);

                // Add tags to the file (including the automatic object tag)
                if (empty($allTags) === false) {
                    $this->attachTagsToFile(fileId: $file->getId(), tags: $allTags);
                }

                //@TODO: This sets the file array of an object, but we should check why this array is not added elsewhere
//                $objectFiles = $objectEntity->getFiles();
//
//                $objectFiles[] = $this->formatFile($file);
//                $objectEntity->setFiles($objectFiles);
//
//                $this->objectEntityMapper->update($objectEntity);

                return $file;

            } catch (NotPermittedException $e) {
                // Log permission error and rethrow exception
                $this->logger->error("Permission denied creating file $fileName: ".$e->getMessage());
                throw new NotPermittedException("Cannot create file $fileName: ".$e->getMessage());
            } catch (\Exception $e) {
                // Log general error and rethrow exception
                $this->logger->error("Failed to create file $fileName: ".$e->getMessage());
                throw new \Exception("Failed to create file $fileName: ".$e->getMessage());
            }
        });
    }//end addFile()

    /**
     * Save a file to an object's folder (create new or update existing).
     *
     * This method provides a generic save functionality that checks if a file already exists
     * for the given object. If it exists, the file will be updated; if not, a new file will
     * be created. This is particularly useful for synchronization scenarios where you want
     * to "upsert" files.
     *
     * @param ObjectEntity $objectEntity The object entity to save the file to
     * @param string       $fileName     The name of the file to save
     * @param string       $content      The content to write to the file
     * @param bool         $share        Whether to create a share link for the file (only for new files)
     * @param array        $tags         Optional array of tags to attach to the file
     *
     * @throws NotPermittedException If file operations fail due to permissions
     * @throws Exception If file operations fail for other reasons
     *
     * @return File The saved file
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param array<int, string> $tags
     */
    public function saveFile(ObjectEntity $objectEntity, string $fileName, string $content, bool $share = false, array $tags = []): File
    {
        try {
            // Check if the file already exists for this object
            $existingFile = $this->getFile(
                object: $objectEntity,
                filePath: $fileName
            );

            if ($existingFile !== null) {
                // File exists, update it
                $this->logger->info("File $fileName already exists for object {$objectEntity->getId()}, updating...");

                // Get the full path for the updateFile method
                $fullPath = $this->getObjectFilePath($objectEntity, $fileName);

                // Update the existing file
                return $this->updateFile(
                    filePath: $fullPath,
                    content: $content,
                    tags: $tags
                );
            } else {
                // File doesn't exist, create it
                $this->logger->info("File $fileName doesn't exist for object {$objectEntity->getId()}, creating...");

                return $this->addFile(
                    objectEntity: $objectEntity,
                    fileName: $fileName,
                    content: $content,
                    share: $share,
                    tags: $tags
                );
            }
        } catch (NotPermittedException $e) {
            // Log permission error and rethrow exception
            $this->logger->error("Permission denied saving file $fileName: ".$e->getMessage());
            throw new NotPermittedException("Cannot save file $fileName: ".$e->getMessage());
        } catch (\Exception $e) {
            // Log general error and rethrow exception
            $this->logger->error("Failed to save file $fileName: ".$e->getMessage());
            throw new \Exception("Failed to save file $fileName: ".$e->getMessage());
        }
    }//end saveFile()

    /**
     * Retrieves all available tags in the system.
     *
     * This method fetches all tags that are visible and assignable by users
     * from the system tag manager, and filters out any tags that start with 'object:'.
     *
     * @throws \Exception If there's an error retrieving the tags
     *
     * @return array An array of tag names
     *
     * @psalm-return array<int, string>
     *
     * @phpstan-return array<int, string>
     */
    public function getAllTags(): array
    {
        try {
            // Get all tags that are visible and assignable by users
            $tags = $this->systemTagManager->getAllTags(visibilityFilter: true);

            // Extract just the tag names and filter out those starting with 'object:'
            $tagNames = array_filter(
                array_map(static function ($tag) {
                    return $tag->getName();
                }, $tags),
                static function ($tagName) {
                    return !str_starts_with($tagName, 'object:');
                }
            );

            // Return sorted array of tag names
            sort($tagNames);
            return array_values($tagNames);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve tags: '.$e->getMessage());
            throw new \Exception('Failed to retrieve tags: '.$e->getMessage());
        }
    }//end getAllTags()

    /**
     * Get all files for an object.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param ObjectEntity|string $object The object or object ID to fetch files for
     *
     * @return Node[] The files found
     *
     * @throws NotFoundException If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function getFiles(ObjectEntity | string $object, ?bool $sharedFilesOnly = false): array
    {
        // If string ID provided, try to find the object entity
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Get the object folder
        $folder = $this->getObjectFolder(
            objectEntity: $object,
            register: $object->getRegister(),
            schema: $object->getSchema()
        );
        $files = $folder->getDirectoryListing();

        if ($sharedFilesOnly === true) {
            foreach ($files as $key => $file) {
                $foundShares = $this->findShares(file: $file);
                if (empty($foundShares) === true || $foundShares = null) {
                    unset($files[$key]);
                }
            }
        }

        // Lets just get the files and let it fall to an error if it's not a folder.
        return $files;
    }

    /**
     * Get files for an object.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param ObjectEntity|string $object   The object or object ID to fetch files for
     * @param string             $filePath The path to the file within the object folder
     *
     * @return File|null The file if found, null otherwise
     *
     * @throws NotFoundException If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     *
     * @psalm-return File|null
     * @phpstan-return File|null
     */
    public function getFile(ObjectEntity | string $object, string $filePath): ?File
    {
        // If string ID provided, try to find the object entity
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Get the object folder
        $folder = $this->getObjectFolder(
            objectEntity: $object,
            register: $object->getRegister(),
            schema: $object->getSchema()
        );


        // Check if folder exists and get the file
        if ($folder instanceof Folder === true) {
            try {
                return $folder->get($filePath);
            } catch (NotFoundException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Publish a file by creating a public share link.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string             $filePath The path to the file to publish
     *
     * @return File The published file
     *
     * @throws Exception If file publishing fails
     * @throws NotFoundException If the file is not found
     * @throws NotPermittedException If sharing is not permitted
     *
     * @psalm-return File
     * @phpstan-return File
     */
    public function publishFile(ObjectEntity | string $object, string $filePath): File
    {
        return $this->executeWithFileUserContext(function () use ($object, $filePath): File {
            // If string ID provided, try to find the object entity
            if (is_string($object) === true) {
                $object = $this->objectEntityMapper->find($object);
            }

            // Debug logging - original file path
            $originalFilePath = $filePath;
            $this->logger->info("publishFile: Original file path received: '$originalFilePath'");

            // Clean and decode the file path
            $filePath = trim(string: $filePath, characters: '/');
            $this->logger->info("publishFile: After trim: '$filePath'");

            $filePath = urldecode($filePath);
            $this->logger->info("publishFile: After urldecode: '$filePath'");

            // Get the object folder (this is where the files actually are)
            $objectFolder = $this->getObjectFolder(
                objectEntity: $object,
                register: $object->getRegister(),
                schema: $object->getSchema()
            );

            if ($objectFolder === false) {
                $this->logger->error("publishFile: Could not get object folder for object: " . $object->getId());
                throw new Exception('Object folder not found.');
            }

            $this->logger->info("publishFile: Object folder path: " . $objectFolder->getPath());

            // Debug: List all files in the object folder
            try {
                $objectFiles = $objectFolder->getDirectoryListing();
                $objectFileNames = array_map(function($file) { return $file->getName(); }, $objectFiles);
                $this->logger->info("publishFile: Files in object folder: " . json_encode($objectFileNames));
            } catch (Exception $e) {
                $this->logger->error("publishFile: Error listing object folder contents: " . $e->getMessage());
            }

            try {
                $this->logger->info("publishFile: Attempting to get file '$filePath' from object folder");
                $file = $objectFolder->get($filePath);
                $this->logger->info("publishFile: Successfully found file: " . $file->getName() . " at " . $file->getPath());
            } catch (NotFoundException $e) {
                $this->logger->error("publishFile: File '$filePath' not found in object folder. NotFoundException: " . $e->getMessage());
                throw new Exception('File not found.');
            } catch (Exception $e) {
                $this->logger->error("publishFile: Unexpected error getting file from object folder: " . $e->getMessage());
                throw new Exception('File not found.');
            }

            // Verify file exists and is a File instance
            if ($file instanceof File === false) {
                $this->logger->error("publishFile: Found node is not a File instance, it's a: " . get_class($file));
                throw new Exception('File not found.');
            }

            $this->logger->info("publishFile: Creating share link for file: " . $file->getPath());

            // Create share link for the file
            $this->createShareLink(path: $file->getPath());

            $this->logger->info("publishFile: Successfully published file: " . $file->getName());
            return $file;
        });
    }

    /**
     * Unpublish a file by removing its public share link.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string             $filePath The path to the file to unpublish
     *
     * @return File The unpublished file
     *
     * @throws Exception If file unpublishing fails
     * @throws NotFoundException If the file is not found
     * @throws NotPermittedException If sharing operations are not permitted
     *
     * @psalm-return File
     * @phpstan-return File
     */
    public function unpublishFile(ObjectEntity | string $object, string $filePath): File
    {
        return $this->executeWithFileUserContext(function () use ($object, $filePath): File {
            // If string ID provided, try to find the object entity
            if (is_string($object) === true) {
                $object = $this->objectEntityMapper->find($object);
            }

            // Debug logging - original file path
            $originalFilePath = $filePath;
            $this->logger->info("unpublishFile: Original file path received: '$originalFilePath'");

            // Clean and decode the file path
            $filePath = trim(string: $filePath, characters: '/');
            $this->logger->info("unpublishFile: After trim: '$filePath'");

            $filePath = urldecode($filePath);
            $this->logger->info("unpublishFile: After urldecode: '$filePath'");

            // Get the object folder (this is where the files actually are)
            $objectFolder = $this->getObjectFolder(
                objectEntity: $object,
                register: $object->getRegister(),
                schema: $object->getSchema()
            );

            if ($objectFolder === false) {
                $this->logger->error("unpublishFile: Could not get object folder for object: " . $object->getId());
                throw new Exception('Object folder not found.');
            }

            $this->logger->info("unpublishFile: Object folder path: " . $objectFolder->getPath());

            // Debug: List all files in the object folder
            try {
                $objectFiles = $objectFolder->getDirectoryListing();
                $objectFileNames = array_map(function($file) { return $file->getName(); }, $objectFiles);
                $this->logger->info("unpublishFile: Files in object folder: " . json_encode($objectFileNames));
            } catch (Exception $e) {
                $this->logger->error("unpublishFile: Error listing object folder contents: " . $e->getMessage());
            }

            try {
                $this->logger->info("unpublishFile: Attempting to get file '$filePath' from object folder");
                $file = $objectFolder->get($filePath);
                $this->logger->info("unpublishFile: Successfully found file: " . $file->getName() . " at " . $file->getPath());
            } catch (NotFoundException $e) {
                $this->logger->error("unpublishFile: File '$filePath' not found in object folder. NotFoundException: " . $e->getMessage());
                throw new Exception('File not found.');
            } catch (Exception $e) {
                $this->logger->error("unpublishFile: Unexpected error getting file from object folder: " . $e->getMessage());
                throw new Exception('File not found.');
            }

            // Verify file exists and is a File instance
            if ($file instanceof File === false) {
                $this->logger->error("unpublishFile: Found node is not a File instance, it's a: " . get_class($file));
                throw new Exception('File not found.');
            }

            $this->logger->info("unpublishFile: Removing share links for file: " . $file->getPath());

            // Remove all share links from the file
            $this->deleteShareLinks(file: $file);

            $this->logger->info("unpublishFile: Successfully unpublished file: " . $file->getName());
            return $file;
        });
    }

    /**
     * Create a ZIP archive containing all files for a specific object.
     *
     * This method retrieves all files associated with an object and creates a ZIP archive
     * containing all the files. The ZIP file is created in the system's temporary directory
     * and can be downloaded by the client.
     *
     * @param ObjectEntity|string $object The object entity or object UUID/ID
     * @param string|null        $zipName Optional custom name for the ZIP file
     *
     * @throws Exception If ZIP creation fails or object not found
     * @throws NotFoundException If the object folder is not found
     * @throws NotPermittedException If file access is not permitted
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     mimeType: string
     * } Information about the created ZIP file
     *
     * @psalm-return array{path: string, filename: string, size: int, mimeType: string}
     * @phpstan-return array{path: string, filename: string, size: int, mimeType: string}
     */
    public function createObjectFilesZip(ObjectEntity | string $object, ?string $zipName = null): array
    {
        return $this->executeWithFileUserContext(function () use ($object, $zipName): array {
            // If string ID provided, try to find the object entity
            if (is_string($object) === true) {
                try {
                    $object = $this->objectEntityMapper->find($object);
                } catch (Exception $e) {
                    throw new Exception("Object not found: " . $e->getMessage());
                }
            }

            $this->logger->info("Creating ZIP archive for object: " . $object->getId());

            // Check if ZipArchive extension is available
            if (class_exists('ZipArchive') === false) {
                throw new Exception('PHP ZipArchive extension is not available');
            }

            // Get all files for the object
            $files = $this->getFiles($object);

            if (empty($files) === true) {
                throw new Exception('No files found for this object');
            }

            $this->logger->info("Found " . count($files) . " files for object " . $object->getId());

            // Generate ZIP filename
            if ($zipName === null) {
                $objectIdentifier = $object->getUuid() ?? (string) $object->getId();
                $zipName = 'object_' . $objectIdentifier . '_files_' . date('Y-m-d_H-i-s') . '.zip';
            } else if (pathinfo($zipName, PATHINFO_EXTENSION) !== 'zip') {
                $zipName .= '.zip';
            }

            // Create temporary file for the ZIP
            $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

            // Create new ZIP archive
            $zip = new \ZipArchive();
            $result = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            if ($result !== true) {
                throw new Exception("Cannot create ZIP file: " . $this->getZipErrorMessage($result));
            }

            $addedFiles = 0;
            $skippedFiles = 0;

            // Add each file to the ZIP archive
            foreach ($files as $file) {
                try {
                    if ($file instanceof \OCP\Files\File === false) {
                        $this->logger->warning("Skipping non-file node: " . $file->getName());
                        $skippedFiles++;
                        continue;
                    }

                    // Get file content
                    $fileContent = $file->getContent();
                    $fileName = $file->getName();

                    // Add file to ZIP with its original name
                    $added = $zip->addFromString($fileName, $fileContent);

                    if ($added === false) {
                        $this->logger->error("Failed to add file to ZIP: " . $fileName);
                        $skippedFiles++;
                        continue;
                    }

                    $addedFiles++;
                    $this->logger->debug("Added file to ZIP: " . $fileName);

                } catch (Exception $e) {
                    $this->logger->error("Error processing file " . $file->getName() . ": " . $e->getMessage());
                    $skippedFiles++;
                    continue;
                }
            }

            // Close the ZIP archive
            $closeResult = $zip->close();
            if ($closeResult === false) {
                throw new Exception("Failed to finalize ZIP archive");
            }

            $this->logger->info("ZIP creation completed. Added: $addedFiles files, Skipped: $skippedFiles files");

            // Check if ZIP file was created successfully
            if (file_exists($tempZipPath) === false) {
                throw new Exception("ZIP file was not created successfully");
            }

            $fileSize = filesize($tempZipPath);
            if ($fileSize === false) {
                throw new Exception("Cannot determine ZIP file size");
            }

            return [
                'path' => $tempZipPath,
                'filename' => $zipName,
                'size' => $fileSize,
                'mimeType' => 'application/zip'
            ];
        });
    }//end createObjectFilesZip()

    /**
     * Get a human-readable error message for ZipArchive error codes.
     *
     * @param int $errorCode The ZipArchive error code
     *
     * @return string Human-readable error message
     *
     * @psalm-return string
     * @phpstan-return string
     */
    private function getZipErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            \ZipArchive::ER_OK => 'No error',
            \ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
            \ZipArchive::ER_RENAME => 'Renaming temporary file failed',
            \ZipArchive::ER_CLOSE => 'Closing zip archive failed',
            \ZipArchive::ER_SEEK => 'Seek error',
            \ZipArchive::ER_READ => 'Read error',
            \ZipArchive::ER_WRITE => 'Write error',
            \ZipArchive::ER_CRC => 'CRC error',
            \ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
            \ZipArchive::ER_NOENT => 'No such file',
            \ZipArchive::ER_EXISTS => 'File already exists',
            \ZipArchive::ER_OPEN => 'Can\'t open file',
            \ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
            \ZipArchive::ER_ZLIB => 'Zlib error',
            \ZipArchive::ER_MEMORY => 'Memory allocation failure',
            \ZipArchive::ER_CHANGED => 'Entry has been changed',
            \ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
            \ZipArchive::ER_EOF => 'Premature EOF',
            \ZipArchive::ER_INVAL => 'Invalid argument',
            \ZipArchive::ER_NOZIP => 'Not a zip archive',
            \ZipArchive::ER_INTERNAL => 'Internal error',
            \ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            \ZipArchive::ER_REMOVE => 'Can\'t remove file',
            \ZipArchive::ER_DELETED => 'Entry has been deleted',
            default => "Unknown error code: $errorCode"
        };
    }//end getZipErrorMessage()

    /**
     * Find all files tagged with a specific object identifier.
     *
     * This method searches for files that have been tagged with the 'object:' prefix
     * followed by the specified object identifier (UUID or ID).
     *
     * @param string $objectIdentifier The object UUID or ID to search for
     *
     * @return array Array of file nodes that belong to the specified object
     *
     * @throws \Exception If there's an error during the search
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function findFilesByObjectId(string $objectIdentifier): array
    {
        return $this->executeWithFileUserContext(function () use ($objectIdentifier): array {
            try {
                // Create the object tag we're looking for
                $objectTag = 'object:' . $objectIdentifier;

                // Get the tag object
                $tag = $this->systemTagManager->getTag(tagName: $objectTag, userVisible: true, userAssignable: true);

                // Get all file IDs that have this tag
                $fileIds = $this->systemTagMapper->getObjectIdsForTags(
                    tagIds: [$tag->getId()],
                    objectType: self::FILE_TAG_TYPE
                );

                $files = [];
                if (empty($fileIds) === false) {
                    // Get the user folder to resolve file paths
                    $userFolder = $this->rootFolder->getUserFolder($this->getUser()->getUID());

                    // Convert file IDs to actual file nodes
                    foreach ($fileIds as $fileId) {
                        try {
                            $file = $userFolder->getById($fileId);
                            if (!empty($file)) {
                                $files = array_merge($files, $file);
                            }
                        } catch (NotFoundException) {
                            // File might have been deleted, skip it
                            continue;
                        }
                    }
                }

                return $files;
            } catch (\Exception $e) {
                $this->logger->error('Failed to find files by object ID: ' . $e->getMessage());
                throw new \Exception('Failed to find files by object ID: ' . $e->getMessage());
            }
        });
    }//end findFilesByObjectId()

}//end class


