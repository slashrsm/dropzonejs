<?php

/**
 * @file
 * Contains \Drupal\dropzonejs\DropzoneJsUploadSave.
 */

namespace Drupal\dropzonejs;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\Validator\Constraints\File;
use Drupal\Core\File\FileSystemInterface;

/**
 * A service that saves files uploaded by the dropzonejs element as file
 * entities.
 *
 * Most of this file mimics or directly copies what core does. For more
 * information and comments see file_save_upload().
 */
class DropzoneJsUploadSave implements DropzoneJsUploadSaveInterface {

  /**
   * Entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Mime type guesser service.
   *
   * @var \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
   */
  protected $mimeTypeGuesser;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface;
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Construct the DropzoneUploadSave object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   Entity manager service.
   * @param \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface $mimetype_guesser
   *   The mime type guesser service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory service.
   */
  public function __construct(EntityManagerInterface $entity_manager, MimeTypeGuesserInterface $mimetype_guesser, FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory, RendererInterface $renderer, ConfigFactoryInterface $config_factory) {
    $this->entityManager = $entity_manager;
    $this->mimeTypeGuesser = $mimetype_guesser;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('dropzonejs');
    $this->renderer = $renderer;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function saveFile($uri, $destination, $extensions, AccountProxyInterface $user, $validators = []) {
    // Create the file entity.
    $file = $this->fileEntityFromUri($uri, $user);

    // Handle potentialy dangerous extensions.
    $renamed = $this->renameExecutableExtensions($file);
    // The .txt extension may not be in the allowed list of extensions. We have
    // to add it here or else the file upload will fail.
    if ($renamed && !empty($extensions)) {
      $extensions .= ' txt';
      drupal_set_message(t('For security reasons, your upload has been renamed to %filename.', ['%filename' => $file->getFilename()]));
    }

    // Validate the file.
    $errors = $this->validateFile($file, $extensions, $validators);
    if (!empty($errors)) {
      $message = [
        'error' => [
          '#markup' => t('The specified file %name could not be uploaded.', ['%name' => $file->getFilename()]),
        ],
        'item_list' => [
          '#theme' => 'item_list',
          '#items' => $errors,
        ],
      ];
      drupal_set_message($this->renderer->renderPlain($message), 'error');
      return FALSE;
    }

    // Prepare destination.
    if (!$this->prepareDestination($file, $destination)) {
      drupal_set_message(t('The file could not be uploaded because the destination %destination is invalid.', ['%destination' => $destination]), 'error');
      return FALSE;
    }

    // Move uploaded files from PHP's upload_tmp_dir to destination.
    $move_result = file_unmanaged_move($uri, $file->getFileUri());
    if (!$move_result) {
      drupal_set_message(t('File upload error. Could not move uploaded file.'), 'error');
      $this->logger->notice('Upload error. Could not move uploaded file %file to destination %destination.', ['%file' => $file->getFilename(), '%destination' => $file->getFileUri()]);
      return FALSE;
    }

    // Set the permissions on the new file.
    $this->fileSystem->chmod($file->getFileUri());

    // If we made it this far it's safe to record this file in the database.
    $file->save();
    return $file;
  }

  /**
   * {@inheritdoc}
   */
  public function fileEntityFromUri($uri, AccountProxyInterface $user) {
    $uri = file_stream_wrapper_uri_normalize($uri);
    $file_info = new \SplFileInfo($uri);

    // Begin building file entity.
    $values = [
      'uid' => $user->id(),
      'status' => 0,
      'filename' => $file_info->getFilename(),
      'uri' => $uri,
      'filesize' => $file_info->getSize(),
      'filemime' => $this->mimeTypeGuesser->guess($uri),
    ];

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityManager->getStorage('file')->create($values);
    return $file;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFile(FileInterface $file, $extensions, array $additional_validators = []) {
    $validators = $additional_validators;

    if (!empty($extensions)) {
      $validators['file_validate_extensions'] = [$extensions];
    }
    $validators['file_validate_name_length'] = [];

    // Call the validation functions specified by this function's caller.
    return file_validate($file, $validators);
  }

  /**
   * Rename potentially executable files.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity object.
   *
   * @return bool
   *   Whether the file was renamed or not.
   */
  protected function renameExecutableExtensions(FileInterface $file) {
    if (!$this->configFactory->get('system.file')->get('allow_insecure_uploads') && preg_match('/\.(php|pl|py|cgi|asp|js)(\.|$)/i', $file->getFilename()) && (substr($file->getFilename(), -4) != '.txt')) {
      $file->setMimeType('text/plain');
      // The destination filename will also later be used to create the URI.
      $file->setFilename($file->getFilename() . '.txt');
      return TRUE;
    }
    return FALSE;
  }


  /**
   * Validate and set destination the destination URI.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity object.
   * @param string $destination
   *   A string containing the URI that the file should be copied to. This must
   *   be a stream wrapper URI.
   *
   * @return bool
   *   True if the destination was sucesfully validated and set, otherwise
   *   false.
   */
  protected function prepareDestination(FileInterface $file, $destination) {
    // Assert that the destination contains a valid stream.
    $destination_scheme = file_uri_scheme($destination);
    if (!file_stream_wrapper_valid_scheme($destination_scheme)) {
      return FALSE;
    }

    // Prepare the destination dir.
    if (!file_exists($destination)) {
      $this->fileSystem->mkdir($destination);
    }

    // A file URI may already have a trailing slash or look like "public://".
    if (substr($destination, -1) != '/') {
      $destination .= '/';
    }
    $file->destination = file_destination($destination . $file->getFilename(), FILE_EXISTS_RENAME);
    $file->setFileUri($file->destination);
    return TRUE;
  }

}
