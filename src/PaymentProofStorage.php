<?php
declare(strict_types=1);

/**
 * Checks and stores the payment proof image (JPG or PNG).
 *
 * Security rules:
 * - the file name typed by the user is NEVER used (a random name is generated);
 * - the file type is detected from the file content, not from its name or the browser's claim;
 * - the file must be a real image of at most 2 MB;
 * - files are stored in storage/, which the web server blocks (see storage/.htaccess);
 *   they are shown only through proof.php, which checks who is asking.
 *
 * Relations: used by RentalService and ExtensionService (save) and by proof.php (findPath).
 *
 * @author Marcella
 * @version 1.0
 */
final class PaymentProofStorage
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const EXTENSIONS = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];

    private string $directory;
    private bool $fromHttpUpload;

    /**
     * @param string $directory folder where the images are saved (must exist)
     * @param bool $fromHttpUpload true on the website (uses move_uploaded_file);
     *                             false in unit tests (uses rename on a normal file)
     */
    public function __construct(string $directory, bool $fromHttpUpload = true)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->fromHttpUpload = $fromHttpUpload;
    }

    /**
     * Checks an uploaded file without saving it.
     *
     * @param mixed $file one entry of $_FILES (name, tmp_name, error, size, type), or null
     * @return string|null an error message, or null if the file is acceptable
     */
    public function validate($file): ?string
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])) {
            return 'Payment proof image is required.';
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return 'Payment proof image is required.';
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'The image is too large (maximum 2 MB).';
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'The upload failed. Please try again.';
        }
        if (!is_string($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return 'The upload failed. Please try again.';
        }
        if ($this->fromHttpUpload && !is_uploaded_file($file['tmp_name'])) {
            return 'The upload failed. Please try again.';
        }
        if ((int)$file['size'] > self::MAX_BYTES || filesize($file['tmp_name']) > self::MAX_BYTES) {
            return 'The image is too large (maximum 2 MB).';
        }
        if ($this->detectType($file['tmp_name']) === null) {
            return 'The file must be a JPG or PNG image.';
        }
        return null;
    }

    /**
     * Validates and saves the file under a random name.
     *
     * @param mixed $file one entry of $_FILES
     * @return string the saved file name (only the name, like "3f9a...c1.png")
     * @throws InvalidArgumentException if the file is not acceptable
     * @throws RuntimeException if the file cannot be saved
     */
    public function save($file): string
    {
        $error = $this->validate($file);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        $imageType = $this->detectType($file['tmp_name']);
        $name = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$imageType];
        $target = $this->directory . '/' . $name;

        $moved = $this->fromHttpUpload
            ? move_uploaded_file($file['tmp_name'], $target)
            : rename($file['tmp_name'], $target);
        if (!$moved) {
            throw new RuntimeException('Could not save the uploaded image.');
        }
        return $name;
    }

    /**
     * Deletes a saved file (used when the database save fails after the upload).
     *
     * @param string $name a name returned by save()
     * @return void
     */
    public function delete(string $name): void
    {
        if (preg_match('/^[0-9a-f]{32}\.(jpg|png)$/', $name) === 1) {
            @unlink($this->directory . '/' . $name);
        }
    }

    /**
     * Full path of a saved file, or null if the name is not one of ours.
     *
     * @param string $name a file name from the database
     * @return string|null the path, or null if the name is invalid or the file is missing
     */
    public function findPath(string $name): ?string
    {
        if (preg_match('/^[0-9a-f]{32}\.(jpg|png)$/', $name) !== 1) {
            return null;
        }
        $path = $this->directory . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /**
     * Reads the real image type from the file content.
     *
     * @param string $path the temporary file
     * @return int|null IMAGETYPE_JPEG or IMAGETYPE_PNG, or null for anything else
     */
    private function detectType(string $path): ?int
    {
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            return null;
        }
        return isset(self::EXTENSIONS[$imageInfo[2]]) ? $imageInfo[2] : null;
    }
}