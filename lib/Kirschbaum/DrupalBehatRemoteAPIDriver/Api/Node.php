<?php namespace Kirschbaum\DrupalBehatRemoteAPIDriver\Api;

use Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\FilterFormatException;

class Node extends BaseDrupalRemoteAPI {

    protected $node_type_get_types;
    protected $field_info_fields;
    protected $filter_formats;
    protected $drupal_filter_format;
    protected $terms_metadata;
    protected $custom_data_tables;
    protected $custom_formatter_class;
    protected $custom_formatter;

    /**
     * Create Node
     */
    public function createNode($node)
    {
        $this->getAndSetRemoteApiMetadata($node);

        // Convert properties to expected structure.
        $this->expandEntityProperties($node);

        // Attempt to decipher any fields that may be specified.
        $node = $this->expandEntityFields($node);

        $result = $this->post('/node', $node);
        $this->confirmResponseStatusCodeIs200($result);
        $newNode['nid'] = (int) $result['id'];
        return (object) $newNode;
    }

    /**
     * Delete Node
     */
    public function deleteNode($node)
    {
       $this->delete('/node/'.$node->nid);
    }

    /**
     *  GET and set Remote API metadata
     */
    protected function getAndSetRemoteApiMetadata($entity)
    {
        $params = $this->getParamsFromObject($entity);
        $response = $this->get('/drupal-remote-api/entities/'.$params);
        $this->confirmResponseStatusCodeIs200($response);
        $this->field_info_fields = $response['data']['field_info_field'];
        $this->node_type_get_types = $response['data']['node_type_get_types'];
        $this->filter_formats = $response['data']['filter_formats'];
    }

    // @TODO More efficient way of doing this.
    protected function getParamsFromObject($entity)
    {
        return implode(',', array_keys(get_object_vars($entity)));
    }

    /**
     *  GET and set Remote API metadata
     * @param $terms
     * @return
     * @throws \Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\DrupalResponseCodeException
     */
    protected function getTermsMetadata($terms)
    {
        $response = $this->get('/drupal-remote-api/terms/'.$terms);
        $this->confirmResponseStatusCodeIs200($response);
        return $this->terms_metadata = $response['data'];
    }

    /**
     * Given an entity object, expand any property fields to the expected structure.
     */
    protected function expandEntityProperties(\stdClass $entity)
    {
        // The created field may come in as a readable date, rather than a timestamp.
        if (isset($entity->created) && !is_numeric($entity->created)) {
            $entity->created = strtotime($entity->created);
        }

        // Map human-readable node types to machine node types.
        foreach ($this->node_type_get_types as $type) {
            if ($entity->type == $type['name']) {
                $entity->type = $type['type'];
                continue;
            }
        }
    }

    /**
     * Given a node object, expand fields to match the format expected by node_save().
     *
     * @param stdClass|\stdClass $entity
     *   Entity object.
     * @param string $entityType
     *   Entity type, defaults to node.
     * @param string $bundle
     *   Entity bundle.
     * @return object
     * @throws \Exception
     */
    protected function expandEntityFields(\stdClass $entity, $entityType = 'node', $bundle = '')
    {
        if ($entityType === 'node' && !$bundle) {
            $bundle = $entity->type;
        }

        $new_entity = clone $entity;
        foreach ($entity as $param => $value) {
            if ($info = $this->field_info_fields[$param]) {
                foreach ($info['bundles'] as $type => $bundles) {
                    if ($type == $entityType) {
                        foreach ($bundles as $target_bundle) {
                            if ($bundle === $target_bundle) {
                                unset($new_entity->{$param});

                                // Use the first defined column. @todo probably breaks things.
                                $column_names = array_keys($info['columns']);
                                $column = array_shift($column_names);

                                // Special handling for date fields (start/end).
                                // @todo generalize this
                                if ('date' === $info['module']) {
                                    // Dates passed in separated by a comma are start/end dates.
                                    $dates = explode(',', $value);
                                    $value = trim($dates[0]);
                                    if (!empty($dates[1])) {
                                        $column2 = array_shift($column_names);
                                        $new_entity->{$param}[$column2] = trim($dates[1]);
                                    }
                                    $new_entity->{$param}[$column] = $value;
                                }

                                // Special handling for term references.
                                elseif ('taxonomy' === $info['module']) {
                                    $terms = $this->getTermsMetadata($value);
                                    foreach ($terms as $term) {
                                        $new_entity->{$param}['id'] = $term['tid'];
                                    }
                                }

                                // Special handling for jQuery Tabs Field.
                                elseif ('field_jquery_tabs' === $info['module']) {

                                    if(isset($this->custom_data_tables[$param]))
                                    {
                                        $table = $this->custom_data_tables[$param];
                                        $row_count = 0;
                                        foreach ($table->getRows() as $row) {
                                            $new_entity->{$param}['tab_title_'.$row_count] = $row[0];
                                            $new_entity->{$param}['tab_body_'.$row_count] = $row[1];
                                            $new_entity->{$param}['tab_format_'.$row_count] = $this->getFilterFormat();
                                            $row_count++;
                                        }
                                    } else {
                                        throw new \Exception(sprintf('Tab data not set for field "%s". There is a custom step to set tab data for this field.', $param));
                                    }
                                }

                                // @TODO Not clear what this is for. Field collections?
                                elseif (is_array($value)) {
                                    foreach ($value as $key => $data) {
                                        if (is_int($key) && (isset($value[$key+1]) || isset($value[$key-1]))) {
                                            $new_entity->{$param}[Node::LANGUAGE_NONE][$key] = $data;
                                        } else {
                                            $new_entity->{$param}[Node::LANGUAGE_NONE][0][$key] = $data;
                                        }
                                    }
                                }

                                elseif ('text_long' === $info['type'] || 'text_with_summary' === $info['type']) {
                                    $new_entity->{$param}[$column] = $value;
                                    $new_entity->{$param}['format'] = $this->getFilterFormat();
                                }

                                else {
                                    $new_entity->{$param} = $value;
                                }

                                // Allow other developers to add custom formatter.
                                if(isset($this->custom_formatter_class)){
                                    $this->processCustomFormatter($info, $new_entity, $param, $column, $value);
                                }
                            }
                        }
                    }
                }
            }
        }

        return get_object_vars($new_entity);
    }

    private function processCustomFormatter($info, $new_entity, $param, $column, $value)
    {
        if(!isset($this->custom_formatter))
        {
            $this->custom_formatter = new $this->custom_formatter_class;
            $this->confirmImplementsCustomFormatterInterface();
        }
        return $this->custom_formatter->process($info, $new_entity, $param, $column, $value, $this->custom_data_tables);
    }

    private function confirmImplementsCustomFormatterInterface()
    {
        if (!in_array('Kirschbaum\DrupalBehatRemoteAPIDriver\CustomFormatterInterface', class_implements($this->custom_formatter))) {
            throw new \Exception(sprintf('Custom formatter class %s must implement Kirschbaum\DrupalBehatRemoteAPIDriver\CustomFormatterInterface.', get_class($this->custom_formatter)));
        }
    }

    public function setDrupalFilterFormat($format)
    {
        $this->drupal_filter_format = $format;
    }

    public function setCustomDataTables($tables)
    {
        $this->custom_data_tables = $tables;
    }

    public function setCustomFormatterClass($class)
    {
        $this->custom_formatter_class = $class;
    }

  /**
   * @return mixed
   * @throws \Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\FilterFormatException
   *
   * If drupal_field_format is provided by a custom step we check to make sure it exists on the remote site.
   * Else we use the lowest weighted field_format from the remote site.
   */
    private function getFilterFormat()
    {
        if (isset($this->drupal_filter_format))
        {
            $this->checkThatFilterFormatExistsOnRemoteSite();
            return $this->drupal_filter_format;
        }
        $default_remote_filter = reset($this->filter_formats);
        return  $this->drupal_filter_format = $default_remote_filter['format'];
    }

    private function checkThatFilterFormatExistsOnRemoteSite()
    {
        if (!isset($this->filter_formats[$this->drupal_filter_format]))
        {
            throw new FilterFormatException(sprintf('The filter format "%s" either does not exist, or this user does not have permission to use it', $this->drupal_filter_format));
        }
    }

}