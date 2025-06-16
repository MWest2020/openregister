<?php
/**
 * OpenRegister Download Service
 *
 * Service class for handling download operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - Downloading objects as files.
 *
 * @category Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use InvalidArgumentException;
use JetBrains\PhpStorm\NoReturn;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IURLGenerator;

/**
 * Service for handling download requests for database entities.
 *
 * This service enables downloading database entities as files in various formats,
 * determined by the `Accept` header of the request. It retrieves the appropriate
 * data from mappers and generates responses or downloadable files.
 */
class DownloadService
{


    /**
     * Constructor for the DownloadService class.
     *
     * @param IURLGenerator  $urlGenerator   The URL generator service.
     * @param SchemaMapper   $schemaMapper   The schema mapper service.
     * @param RegisterMapper $registerMapper The register mapper service.
     *
     * @return void
     */
    public function __construct(
        private IURLGenerator $urlGenerator,
        private SchemaMapper $schemaMapper,
        private RegisterMapper $registerMapper
    ) {

    }//end __construct()


    /**
     * Download a DB entity as a file. Depending on given Accept-header the file type might differ.
     *
     * @param string     $objectType The type of object to download.
     * @param string|int $id         The id of the object to download.
     * @param string     $accept     The Accept-header from the download request.
     *
     * @throws Exception
     *
     * @return array The response data for the download request.
     */
    public function download(string $objectType, string | int $id, string $accept): array
    {
        // Get the appropriate mapper for the object type.
        $mapper = $this->getMapper($objectType);

        try {
            $object = $mapper->find($id);
        } catch (Exception $exception) {
            return ['error' => "Could not find $objectType with id $id.", 'statusCode' => 404];
        }

        $objectArray = $object->jsonSerialize();
        $filename    = $objectArray['title'].ucfirst($objectType).'-v'.$objectArray['version'];

        if (str_contains($accept, 'application/json') === true || $accept === '*/*') {
            $url = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('openregister.'.ucfirst($objectType).'s.show', ['id' => $object->getId()])
            );

            $objArray['title']   = $objectArray['title'];
            $objArray['$id']     = $url;
            $objArray['$schema'] = 'https://docs.commongateway.nl/schemas/'.ucfirst($objectType).'.schema.json';
            $objArray['version'] = $objectArray['version'];
            $objArray['type']    = $objectType;
            unset($objectArray['title'], $objectArray['version'], $objectArray['id'], $objectArray['uuid']);
            $objArray = array_merge($objArray, $objectArray);

            // Convert the object data to JSON.
            $jsonData = json_encode($objArray, JSON_PRETTY_PRINT);

            $this->downloadJson($jsonData, $filename);
        }

        return ['error' => "The Accept type $accept is not supported.", 'statusCode' => 400];

    }//end download()


    /**
     * Generate a downloadable json file response.
     *
     * @param string $jsonData The json data to create a json file with.
     * @param string $filename The filename, .json will be added after this filename in this function.
     *
     * @return void
     */
    private function downloadJson(string $jsonData, string $filename): void
    {
        // Define the file name and path for the temporary JSON file.
        $fileName = $filename.'.json';
        $filePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$fileName;

        // Create and write the JSON data to the file.
        file_put_contents($filePath, $jsonData);

        // Set headers to download the file.
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="'.$fileName.'"');
        header('Content-Length: '.filesize($filePath));

        // Output the file contents.
        readfile($filePath);

        // Clean up: delete the temporary file.
        unlink($filePath);

        // Ensure no further script execution.
        exit;

    }//end downloadJson()


    /**
     * Gets the appropriate mapper based on the object type.
     *
     * @param string $objectType The type of object to retrieve the mapper for.
     *
     * @throws InvalidArgumentException If an unknown object type is provided.
     * @throws Exception
     *
     * @return mixed The appropriate mapper.
     */
    private function getMapper(string $objectType): mixed
    {
        $objectTypeLower = strtolower($objectType);

        // If the source is internal, return the appropriate mapper based on the object type.
        return match ($objectTypeLower) {
            'schema' => $this->schemaMapper,
            'register' => $this->registerMapper,
        default => throw new InvalidArgumentException("Unknown object type: $objectType"),
        };

    }//end getMapper()


}//end class
