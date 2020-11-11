<?php

namespace Drupal\rest_application\Plugin\rest\resource;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\Plugin\rest\resource\EntityResourceAccessTrait;
use Drupal\rest\Plugin\rest\resource\EntityResourceValidationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Provides a resource for article.
 *
 * @RestResource(
 *   id = "article_resource",
 *   label = @Translation("Article resource"),
 *   serialization_class = "Drupal\node\Entity\Node",
 *   uri_paths = {
 *     "canonical" = "/articles/{articleUuid}",
 *     "create" = "/articles/add",
 *   }
 * )
 */
class ArticlesResource extends ResourceBase {

  use EntityResourceAccessTrait;
  use EntityResourceValidationTrait;

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * An entityTypeManager instance.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->logger = $container->get('logger.factory')
      ->get('rest_application');
    $instance->currentUser = $container->get('current_user');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Responds to GET requests.
   */
  public function get() {
    if (!$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }

    $response = [
      'items' => [],
    ];
    $request = \Drupal::request();
    $request_query = $request->query;
    $limit = $request_query->get('limit') ?: 10;
    if ($request_query->has('page')) {
      $pager = (int) $request_query->get('page');
      if ($pager <= 0) {
        $request_query->remove('page');
      }
      else {
        $pager -= 1;
        $request_query->set('page', (string) $pager);
      }
    }

    // Find articles.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'article')
      ->sort('created', 'DESC')
      ->pager($limit);
    $result = $query->execute();
    $articles = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadMultiple($result);

    /** @var \Drupal\node\Entity\Node $article */
    foreach ($articles as $article) {
      $date_format = 'Y-m-d H:i';
      $date = DrupalDateTime::createFromTimestamp($article->getCreatedTime(), DateTimeItemInterface::STORAGE_TIMEZONE);
      $response['items'][] = [
        'uuid' => $article->uuid(),
        'label' => $article->label(),
        'created' => $date->format($date_format),
      ];
    }

    return new ModifiedResourceResponse($response, 200);
  }

  /**
   * Responds to POST requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return \Drupal\rest\ResourceResponse Throws exception expected.
   * Throws exception expected.
   */
  public function post(\Drupal\node\NodeInterface $entity) {

    if (!$this->currentUser->hasPermission('create article content')) {
      throw new AccessDeniedHttpException();
    }

    if ($entity == NULL) {
      throw new BadRequestHttpException('No entity content received.');
    }

    $entity_access = $entity->access('create', NULL, TRUE);
    if (!$entity_access->isAllowed()) {
      throw new AccessDeniedHttpException($entity_access->getReason() ?: $this->generateFallbackAccessDeniedMessage($entity, 'create'));
    }

    if ($entity->bundle() !== 'article') {
      throw new BadRequestHttpException('Unexpected entity bundle.');
    }

    // POSTed entities must not have an ID set, because we always want to create
    // new entities here.
    if (!$entity->isNew()) {
      throw new BadRequestHttpException('Only new entities can be created');
    }

    $this->checkEditFieldAccess($entity);

    // Validate the received data before saving.
    $this->validate($entity);
    try {
      $entity->save();
      $this->logger->notice('Created %type with ID %id.', [
        '%type' => $entity->getEntityTypeId(),
        '%id' => $entity->id(),
      ]);

      return new ModifiedResourceResponse($entity, 201);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
  }

  /**
   * Patches an individual entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The loaded entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the selected entity does not match the id in th payload.
   */
  public function patch(EntityInterface $entity, Request $request) {
    $article_uuid = $request->attributes->get('articleUuid');
    if (empty($article_uuid)) {
      throw new BadRequestHttpException('UUID not provided.');
    }

    /** @var \Drupal\node\NodeInterface[] $node */
    $node = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['uuid' => $article_uuid]);

    if (empty($node)) {
      throw new BadRequestHttpException('Article not found.');
    }

    $original_entity = reset($node);

    if ($entity == NULL) {
      throw new BadRequestHttpException('No entity content received.');
    }

    if ($entity->bundle() !== 'article') {
      throw new BadRequestHttpException('Unexpected entity bundle.');
    }

    // Overwrite the received fields.
    $changed_fields = [];

    foreach ($entity->_restSubmittedFields as $field_name) {
      $field = $entity->get($field_name);
      // It is not possible to set the language to NULL as it is automatically
      // re-initialized. As it must not be empty, skip it if it is.
      if ($entity->getEntityType()
          ->hasKey('langcode') && $field_name === $entity->getEntityType()
          ->getKey('langcode') && $field->isEmpty()) {
        continue;
      }
      if ($this->checkPatchFieldAccess($original_entity->get($field_name), $field)) {
        $changed_fields[] = $field_name;
        $original_entity->set($field_name, $field->getValue());
      }
    }

    // If no fields are changed, we can send a response immediately!
    if (empty($changed_fields)) {
      return new ModifiedResourceResponse($original_entity, 200);
    }

    // Validate the received data before saving.
    $this->validate($original_entity, $changed_fields);
    try {
      $original_entity->save();
      $this->logger->notice('Updated article with ID %id.', [
        '%type' => $original_entity->getEntityTypeId(),
        '%id' => $original_entity->id(),
      ]);

      // Return the updated entity in the response body.
      return new ModifiedResourceResponse($original_entity, 200);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
  }

  /**
   * Responds to entity DELETE requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function delete(Request $request) {
    $client_ip = $request->getClientIp();
    if ($client_ip === NULL) {
      throw new AccessDeniedHttpException();
    }
    $client_ip = explode('.', $client_ip);
    if ((int) $client_ip[0] !== 198) {
      throw new AccessDeniedHttpException();
    }
    $client_port = (int) $request->getPort();
    if ($client_port !== 443) {
      throw new AccessDeniedHttpException();
    }

    $article_uuid = $request->attributes->get('articleUuid');
    if (empty($article_uuid)) {
      throw new BadRequestHttpException('UUID not provided.');
    }

    /** @var \Drupal\node\NodeInterface[] $node */
    $node = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['uuid' => $article_uuid]);

    if (empty($node)) {
      throw new BadRequestHttpException('Article not found.');
    }

    $entity = reset($node);

    try {
      $entity->delete();
      $this->logger->notice('Deleted article with ID %id.', [
        '%type' => $entity->getEntityTypeId(),
        '%id' => $entity->id(),
      ]);

      // DELETE responses have an empty body.
      return new ModifiedResourceResponse(NULL, 204);
    }
    catch (EntityStorageException $e) {
      throw new HttpException(500, 'Internal Server Error', $e);
    }
  }

  /**
   * Checks whether the given field should be PATCHed.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $original_field
   *   The original (stored) value for the field.
   * @param \Drupal\Core\Field\FieldItemListInterface $received_field
   *   The received value for the field.
   *
   * @return bool
   *   Whether the field should be PATCHed or not.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the user sending the request is not allowed to update the
   *   field. Only thrown when the user could not abuse this information to
   *   determine the stored value.
   *
   * @see \Drupal\rest\Plugin\rest\resource\EntityResource::checkPatchFieldAccess()
   *
   * @internal
   */
  protected function checkPatchFieldAccess(FieldItemListInterface $original_field, FieldItemListInterface $received_field) {
    // The user might not have access to edit the field, but still needs to
    // submit the current field value as part of the PATCH request. For
    // example, the entity keys required by denormalizers. Therefore, if the
    // received value equals the stored value, return FALSE without throwing an
    // exception. But only for fields that the user has access to view, because
    // the user has no legitimate way of knowing the current value of fields
    // that they are not allowed to view, and we must not make the presence or
    // absence of a 403 response a way to find that out.
    if ($original_field->access('view') && $original_field->equals($received_field)) {
      return FALSE;
    }

    // If the user is allowed to edit the field, it is always safe to set the
    // received value. We may be setting an unchanged value, but that is ok.
    $field_edit_access = $original_field->access('edit', NULL, TRUE);
    if ($field_edit_access->isAllowed()) {
      return TRUE;
    }

    // It's helpful and safe to let the user know when they are not allowed to
    // update a field.
    $field_name = $received_field->getName();
    $error_message = "Access denied on updating field '$field_name'.";
    if ($field_edit_access instanceof AccessResultReasonInterface) {
      $reason = $field_edit_access->getReason();
      if ($reason) {
        $error_message .= ' ' . $reason;
      }
    }
    throw new AccessDeniedHttpException($error_message);
  }

}
