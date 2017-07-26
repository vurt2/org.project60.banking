<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/


/**
 * This PostProcessor call an API action if triggered
 */
class CRM_Banking_PluginImpl_PostProcessor_API extends CRM_Banking_PluginModel_PostProcessor {

  /**
   * class constructor
   */
  function __construct($config_name) {
    parent::__construct($config_name);

    // read config, set defaults
    $config = $this->_plugin_config;

    if (!isset($config->entity))            $config->entity = NULL;
    if (!isset($config->action))            $config->action = NULL;
    if (!isset($config->params))            $config->params = array();
    if (!isset($config->param_propagation)) $config->param_propagation = array();
  }

  /**
   * Should this postprocessor spring into action?
   * Evaluates the common 'required' fields in the configuration
   *
   * @param $match    the executed match
   * @param $btx      the related transaction
   * @param $context  the matcher context contains cache data and context information
   *
   * @return bool     should the this postprocessor be activated
   */
  protected function shouldExecute(CRM_Banking_Matcher_Suggestion $match, CRM_Banking_PluginModel_Matcher $matcher, CRM_Banking_Matcher_Context $context) {
    $config = $this->_plugin_config;

    // check if an entity is set
    if (empty($config->entity)) {
      return FALSE;
    }

    // check if an action is set
    if (empty($config->action)) {
      return FALSE;
    }

    // pass on to parent to check generic reasons
    return parent::shouldExecute($match, $matcher, $context);
  }


  /**
   * Postprocess the (already executed) match
   *
   * @param $match    the executed match
   * @param $btx      the related transaction
   * @param $context  the matcher context contains cache data and context information
   *
   */
  public function processExecutedMatch(CRM_Banking_Matcher_Suggestion $match, CRM_Banking_PluginModel_Matcher $matcher, CRM_Banking_Matcher_Context $context) {
    $config = $this->_plugin_config;

    if ($this->shouldExecute($match, $matcher, $context)) {
      // compile call parameters
      $params = array();
      foreach ($config->params as $key => $value) {
        if ($value !== NULL) {
          $params[$key] = $value;
        }
      }

      foreach ($config->param_propagation as $value_source => $value_key) {
        $value = $this->getPropagationValue($context->btx, $match, $value_source);
        if ($value !== NULL) {
          $params[$value_key] = $value;
        }
      }

      // perform the call
      try {
        // TODO: log
        error_log("CALLING {$config->entity}.{$config->action} with " . json_encode($params));
        civicrm_api3($config->entity, $config->action, $params);
      } catch (Exception $e) {
        // TODO: log
        error_log("CALLING {$config->entity}.{$config->action} failed: " . $e->getMessage());
      }
    }
  }
}

