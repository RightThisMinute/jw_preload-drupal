<?php
declare(strict_types=1);


/**
 * Associate JW media IDs with paths in order for their the metadata for those
 * media IDs to be preloaded and made available via the Drupal global JS object.
 *
 * @param string $path
 *   An internal, non-alias path.
 * @param stdClass|null $entity
 *   The entity associated with this path, if any.
 * @param string|null $entity_type
 *   The type of entity associated with this path, if any.
 * @param string|null $entity_id
 *   The ID of an entity associated with this path, if any.
 *
 * @return string[]
 *   A list of JW media IDs.
 */
function hook_jw_preload_register_media_ids
  (string $path, ?\stdClass $entity, ?string $entity_type, ?string $entity_id)
  : array
{
  # Find JW media IDs of videos that would show up on the passed path.
  return ['abcd1234', 'efgh5678'];
}
