<?php

namespace Drupal\acquia_migrate;

use Drupal\Core\Serialization\Yaml;

/**
 * Analyzes migration messages.
 *
 * @internal
 */
final class MessageAnalyzer {

  /**
   * The potential solutions to evaluate.
   *
   * @var array
   */
  private $solutions;

  /**
   * MessageAnalyzer constructor.
   */
  public function __construct() {
    $this->solutions = Yaml::decode(file_get_contents(__DIR__ . '/../messages-solutions.yml'));
  }

  /**
   * Computes the solution for a given message in a given migration plugin.
   *
   * @param string $migration_plugin_id
   *   The migration plugin that generated the message.
   * @param string $message
   *   The message to provide a solution for.
   *
   * @return string|null
   *   A solution for the message, if any.
   */
  public function getSolution(string $migration_plugin_id, string $message) : ?string {
    [$base_migration_plugin_id] = explode(':', $migration_plugin_id);

    $candidate_solutions = !isset($this->solutions[$base_migration_plugin_id])
      ? $this->solutions['FALLBACK']
      : array_merge($this->solutions[$base_migration_plugin_id], $this->solutions['FALLBACK']);

    for ($i = 0; $i < count($candidate_solutions); $i++) {
      if (preg_match('/' . $candidate_solutions[$i]['message']['regexp'] . '/', $message, $matches)) {
        $per = $candidate_solutions[$i]['message']['specific_solution_per'] ?? NULL;
        // Suggest a specific solution if possible.
        if (isset($matches[$per])) {
          // Allow even computed specific solutions.
          if (isset($candidate_solutions[$i]['message']['computed_specific_solution']['args'])) {
            $args = $candidate_solutions[$i]['message']['computed_specific_solution']['args'];
            foreach ($args as $key => $value) {
              if ($value[0] === '@' && array_key_exists(substr($value, 1), $matches)) {
                $args[$key] = $matches[substr($value, 1)];
                // Break because for now only a single variable is supported.
                break;
              }
            }
          }
          // @todo When the need arises, formalize this callback system.
          $available_callbacks = [
            'source_db_table_row_exists',
            'source_db_table_row_has_null_column',
            'source_db_table_row_has_empty_column',
          ];
          if (isset($candidate_solutions[$i]['message']['computed_specific_solution']) && !in_array($candidate_solutions[$i]['message']['computed_specific_solution']['callback'], $available_callbacks, TRUE)) {
            throw new \InvalidArgumentException('Invalid computed_specific_solution callback specified.');
          }
          if (isset($candidate_solutions[$i]['message']['computed_specific_solution']) && $candidate_solutions[$i]['message']['computed_specific_solution']['callback'] === 'source_db_table_row_exists') {
            $exists_or_not = SourceDatabase::getConnection()->select($args[0])->condition($args[1], $args[2])->countQuery()->execute()->fetchField()
              ? 'exists'
              : 'does_not_exist';
            if (isset($candidate_solutions[$i]['computed_specific_solution'][$exists_or_not])) {
              return str_replace("@$per", $matches[$per], $candidate_solutions[$i]['computed_specific_solution'][$exists_or_not]);
            }
          }
          elseif (isset($candidate_solutions[$i]['message']['computed_specific_solution']) && $candidate_solutions[$i]['message']['computed_specific_solution']['callback'] === 'source_db_table_row_has_null_column') {
            $is_null_or_not = SourceDatabase::getConnection()->select($args[0])->condition($args[1], NULL, 'IS NULL')->countQuery()->execute()->fetchField()
              ? 'is_null'
              : 'is_not_null';
            if (isset($candidate_solutions[$i]['computed_specific_solution'][$is_null_or_not])) {
              return str_replace("@$per", $matches[$per], $candidate_solutions[$i]['computed_specific_solution'][$is_null_or_not]);
            }
          }
          elseif (isset($candidate_solutions[$i]['message']['computed_specific_solution']) && $candidate_solutions[$i]['message']['computed_specific_solution']['callback'] === 'source_db_table_row_has_empty_column') {
            $is_empty_or_not = SourceDatabase::getConnection()->select($args[0])->condition($args[1], '', 'LIKE')->countQuery()->execute()->fetchField()
              ? 'is_empty'
              : 'is_not_empty';
            if (isset($candidate_solutions[$i]['computed_specific_solution'][$is_empty_or_not])) {
              return str_replace("@$per", $matches[$per], $candidate_solutions[$i]['computed_specific_solution'][$is_empty_or_not]);
            }
          }
          elseif (isset($candidate_solutions[$i]['specific_solution'][$matches[$per]])) {
            return $candidate_solutions[$i]['specific_solution'][$matches[$per]];
          }
        }
        // Otherwise suggest the generic solution.
        if (isset($matches[$per])) {
          return str_replace("@$per", $matches[$per], $candidate_solutions[$i]['generic_solution']);
        }
        else {
          return $candidate_solutions[$i]['generic_solution'];
        }
      }
    }

    // No suggested solution for this particular message. This is tracked in the
    // aggregate logging so we can grow the set of suggested solutions.
    // @see \Drupal\acquia_migrate\Plugin\migrate\id_map\SqlWithCentralizedMessageStorage::saveMessage()
    return NULL;
  }

}
