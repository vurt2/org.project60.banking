<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2013-2014 SYSTOPIA                       |
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
 *
 * @package org.project60.banking
 * @copyright GNU Affero General Public License
 * $Id$
 *
 */
abstract class CRM_Banking_PluginModel_Matcher extends CRM_Banking_PluginModel_Base {

  protected static $_cache = NULL;
  
  protected $_suggestions;

  protected function addSuggestion( $suggestion )
  {
      $this->_suggestions[] = $suggestion;
    }
        
  // ------------------------------------------------------
  // Functions to be provided by the plugin implementations
  // ------------------------------------------------------
  /** 
   * Report if the plugin is capable of batch matching (v2+?)
   * 
   * @return bool
   */
  function does_batch_matching() { return FALSE; }

  /** 
   * Generate a set of suggestions for the given bank transaction
   * 
   * @return array(match structures)
   */
  public abstract function match(CRM_Banking_BAO_BankTransaction $btx, CRM_Banking_Matcher_Context $context);

  /** 
   * Executes a previously generated match, i.e. the suggestion is accepted and realized
   *
   * Obviously, this method should be overwritten by the individual matchers,
   *  but DON'T forget to call parent::execute($match, $btx);
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return TODO: what?
   */
  public function execute( $match, $btx ) {
    $match->setExecuted();
    $btx->saveSuggestions();
  }


  /** 
   * Generate html code to visualize the given match. The visualization may also provide interactive form elements.
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */  
  function visualize_match( CRM_Banking_Matcher_Suggestion $match, $btx) {
    $html = "<p>".ts("Because :")."<ul>";
    $evidence = $match->getEvidence();
    foreach ($evidence as $ev) {
        $html .= '<li>' . $ev . '</li>';
    }
    $html .= '</ul></p>';
    return $html;
  }

  /** 
   * Generate html code to visualize the executed match.
   * 
   * @val $match    match data as previously generated by this plugin instance
   * @val $btx      the bank transaction the match refers to
   * @return html code snippet
   */  
  function visualize_execution_info( CRM_Banking_Matcher_Suggestion $match, $btx) {
      // TODO: implement
      $s = '<p>'.ts('No further information available').'</p>';
      return $s;
  }

  /**
   * If the user has modified the input fields provided by the "visualize" html code,
   * the new values will be passed here BEFORE execution
   *
   * CAUTION: there might be more parameters than provided. Only process the ones that
   *  'belong' to your suggestion.
   */
  public function update_parameters(CRM_Banking_Matcher_Suggestion $match, $parameters) {
      // Noting to do in the abstract matcher. Override for a matcher that uses input fields
  }


  /**
   * class constructor
   */ 
  function __construct($config_name) {
    $this->_suggestions = array();
    parent::__construct($config_name);
  }

  /** 
   * Returns the threshold for automatic execution as set in the config
   * 
   * @return float ([0..1]) 
   */
  function getThreshold() { 
    if (isset($this->_plugin_config->threshold)) {
      $threshold = (float) $this->_plugin_config->threshold;
      if ($threshold >= 1.0) {
        return 1.0;
      } elseif ($threshold <= 0.0) {
        return 0.0;
      } else {
        return $threshold;
      }
    }
    return 1.0; 
  }

  /** 
   * Returns whether the plugin is configured to execute unsupervised
   * 
   * @return bool
   */
  function autoExecute() { 
    if (isset($this->_plugin_config->auto_exec)) {
      $value = $this->_plugin_config->auto_exec;
      if ($value===true || $value==='true') {
        return 1.0;
      } elseif (($value >= 0.0) && ($value <= 1.0)) {
        return (float) $value;
      } else {
        return 0.0;
      }
    } else {
      return 0.0;
    }
  }


  /**
   * Checks if the preconditions defined by required_values are met
   * 
   * @return TRUE iff all values are as expected (or none are specified)
   */
  public function requiredValuesPresent(CRM_Banking_BAO_BankTransaction $btx) {
    $config = $this->_plugin_config;

    // nothing specified => ALL CLEAR
    if (empty($config->required_values)) return TRUE;

    // check array type
    if (is_object($config->required_values)) {
      // this is an ASSOCIATIVE array
      foreach ($config->required_values as $required_key => $required_value) {
        $current_value = $this->getPropagationValue($btx, NULL, $required_key);
        $split = split(':', $required_value, 2);
        if (count($split) < 2) {
          error_log("org.project60.banking: required_value in config option not properly formatted, plugin id [{$this->_plugin_id}]");
        } else {
          $command = $split[0];
          $parameter = $split[1];
          if ($command == 'equal') {
            if ($current_value != $parameter) return FALSE;
          } elseif ($command == 'in') {
            $exptected_values = explode(",", $parameter);
            foreach ($exptected_values as $exptected_value) {
              if ($current_value == $exptected_value) continue;
            }
            return FALSE; // not in set
          } elseif ($command == 'not_in') {
            $exptected_values = explode(",", $parameter);
            foreach ($exptected_values as $exptected_value) {
              if ($current_value == $exptected_value) return FALSE;
            }
          } else {
            error_log("org.project60.banking: unknwon command '$command' in required_value in config of plugin id [{$this->_plugin_id}]");
          }
        }
      }      

    } elseif (is_array($config->required_values)) {
      // this is a SEQUENTIAL array -> simply check if they are there
      foreach ($config->required_values as $required_key) {
        if ($this->getPropagationValue($btx, NULL, $required_key)==NULL) {
          // there is no value given for this key => bail
          return FALSE;
        }
      }

    } else {
      error_log("org.project60.banking: WARNING: required_values config option not properly set, plugin id [{$this->_plugin_id}]");      
    }

    return TRUE;
  }


  /**************************************************************
   *                   value propagation                        *
   * allows for a config-driven propagation of extracted values *
   *                                                            *
   * Propagation values are data that has been gathered some-   *
   * where during the process. This data (like financial_type,  *
   * campaign_id, etc.) can then be passed on to the final      *
   * objects, e.g. contributions                                *
   **************************************************************/

  /**
   * Get the propagation keys
   * If a subset (e.g. 'contribution') is given, only 
   * the keys targeting this entity are returned
   */
  public function getPropagationKeys($subset='', $propagation_config = NULL) {
    if ($propagation_config==NULL) {
      $propagation_config = $this->_plugin_config->value_propagation;
    }
    $keys = array();
    if (!isset($propagation_config)) {
      return $keys;
    }

    foreach ($propagation_config as $key => $target_key) {
      if ($subset) {
        if (substr($target_key, 0, strlen($subset))==$subset) {
          $keys[$key] = $target_key;
        }
      } else {
        $keys[$key] = $target_key;
      }
    }
    return $keys;
  }

  /** 
   * Get the value of the propagation value spec
   */
  public function getPropagationValue($btx, $suggestion, $key) {
    $key_bits = split("[.]", $key, 2);
    if ($key_bits[0]=='ba' || $key_bits[0]=='party_ba') {
      // read bank account stuff
      if ($key_bits[0]=='ba') {
        $bank_account = $btx->getBankAccount();
      } else {
        $bank_account = $btx->getPartyBankAccount();
      }

      if ($bank_account==NULL) {
        return NULL;
      }

      if (isset($bank_account->$key_bits[1])) {
        // look in the BA directly
        return $bank_account->$key_bits[1];
      } else {
        // look in the parsed values
        $data = $bank_account->getDataParsed();
        if (isset($data[$key_bits[1]])) {
          return $data[$key_bits[1]];
        } else {
          return NULL;
        }
      }

    } elseif ($key_bits[0]=='suggestion' || $key_bits[0]=='match') {
      // read suggestion parameters
      if ($suggestion != NULL) {
        return $suggestion->getParameter($key_bits[1]);
      } else {
        return NULL;
      }

    } elseif ($key_bits[0]=='btx') {
      // read BTX stuff
      if (isset($btx->$key_bits[1])) {
        // look in the BA directly
        return $btx->$key_bits[1];
      } else {
        // look in the parsed values
        $data = $btx->getDataParsed();
        if (isset($data[$key_bits[1]])) {
          return $data[$key_bits[1]];
        } else {
          return NULL;
        }
      }
    }

    return NULL;
  }

  /**
   * Get the key=>value set of the propagation values
   *
   * if a subset is specified (e.g. 'contribution')
   * only the targets with prefix contribution will be 
   * read, and the 
   * 
   * example:
   *   in config:  '"value_propagation": { "party_ba.name": "contribution.source" }'
   *
   * with getPropagationSet($btx, 'contribution') you get:
   *   array("source" => "Looked-up bank owner's name")
   *
   * ...which you can pass right into your create contribtion call
   */
  public function getPropagationSet($btx, $suggestion, $subset = '', $propagation_config = NULL) {
    $propagation_set = $this->getPropagationKeys($subset, $propagation_config);
    $propagation_values = array();

    foreach ($propagation_set as $key => $target_key) {
      $value = $this->getPropagationValue($btx, $suggestion, $key);
      if ($value != NULL) {
        $propagation_values[substr($target_key, strlen($subset)+1)] = $value;
      }
    }

    return $propagation_values;
  }


  /**************************************************************
   *                    Matching Variables                      *
   **************************************************************/
  /**
   * Will get the list of variables defined by the "variables" tag in the config
   *
   * @return array of variable names
   */
  function getVariableList() {
    if (isset($this->_plugin_config->variables)) {
      $variables = (array) $this->_plugin_config->variables;
      return array_keys($variables);
    }
    return array();
  }

  /**
   * Will get a variable as defined by the "variables" tag in the config
   *
   * @param $context  CRM_Banking_Matcher_Context instance, will be used for caching
   * @return the variables length
   */
  function getVariable($name) {
    if (isset($this->_plugin_config->variables->$name)) {
      $variable_spec = $this->_plugin_config->variables->$name;
      if (!empty($variable_spec->cached)) {
        // check the cache
        $value = CRM_Utils_StaticCache::getCachedEntry('var_'.$name);
        if ($value != NULL) {
          return $value;
        }
      }

      // get value
      if ($variable_spec->type == 'SQL') {
        $value = array();
        $querySQL = "SELECT " . $variable_spec->query . ";";
        try {
          $query = CRM_Core_DAO::executeQuery($querySQL);
          while ($query->fetch()) {
            $value[] = $query->value;
          }
        } catch (Exception $e) {
          error_log("org.project60.banking.matcher: there was an error with SQL statement '$querySQL'");
        }
        if (isset($variable_spec->glue)) {
          $value = implode($variable_spec->glue, $value);
        }
      } else {
        error_log("org.project60.banking.matcher: unknown variable type '{$variable_spec->type}'.");
      }
  
      if (!empty($variable_spec->cached)) {
        // set cache value
        CRM_Utils_StaticCache::setCachedEntry('var_'.$name, $value);
      }

      return $value;
    }

    return NULL;
  }

  /**************************************************************
   *                      Penalty rules                         *
   **************************************************************/
  
  /**
   * get a general penalty value to be applied to all suggestions
   * based on the 'penalties' rule set in the configuration
   */
  function getPenalty($btx) {
    $config = $this->_plugin_config;
    if (empty($config->penalties)) return 0.0;
    $penalty = 0.0;

    // execute the rules
    foreach ($config->penalties as $penalty_rule) {

      if ($penalty_rule->type == 'constant') {
        // CONSTANT PENALTY
        $penalty += $penalty_rule->amount;
        $trigger_count -= 1;

      } elseif ($penalty_rule->type == 'suggestion') {
        // OTHER SUGGESTIONS PENALTY 

        // first: see, how many times this roule should be triggered at most
        if (!empty($penalty_rule->max_trigger_count)) {
          $trigger_count = (int) $penalty_rule->max_trigger_count;
        } else {
          $trigger_count = 1;
        }

        foreach ($btx->getSuggestionList() as $suggestion) {
          $matcher = $suggestion->getPlugin();
          if (!empty($penalty_rule->threshold) 
            && $suggestion->getProbability() < $penalty_rule->threshold) continue;
          if (!empty($penalty_rule->suggestion_type) 
            && $matcher->getTypeName() != $penalty_rule->suggestion_type) continue;

          if ($trigger_count <= 0) break;

          // if we get here, the penalty is supposed to be applied
          $penalty += $penalty_rule->amount;
          $trigger_count -= 1;
        }

      } elseif ($penalty_rule->type == 'attribute') {
        // ATTRIBUTE/VARIABLE PENALTY
        // TODO: Implement

      } else {
        error_log("org.project60.banking.matcher: penalty type unknwon: " . $penalty_rule->type);        
      }
    }
    return $penalty;
  }


  /**************************************************************
   *                  Store Account Data                        *
   **************************************************************/

  /**
   * Will store the donor's account data
   *
   * @todo move to post processors
   */
  function storeAccountWithContact($btx, $contact_id) {
    // find all reference types
    $reference_type_group = array('name' => 'civicrm_banking.reference_types');
    $reference_types = array();
    CRM_Core_OptionValue::getValues($reference_type_group, $reference_types);

    // gather the information
    $data = $btx->getDataParsed();
    $references = array();
    foreach ($reference_types as $reference_type) {
      $field_name = '_party_'.$reference_type['name'];
      if (!empty($data[$field_name])) {
        $references[$reference_type['id']] = $data[$field_name];
      }
    }

    // if we don't have references, there's nothing we can do...
    if (empty($references)) return;

    // gather account info
    $account_info = array();
    if (!empty($data['_party_BIC'])) $account_info['BIC'] = $data['_party_BIC'];
    if (!empty($data['_party_IBAN'])) $account_info['country'] = substr($data['_party_IBAN'], 0, 2);
    // copy all entries that start with _party_ba_ into the account info
    foreach ($data as $key => $value) {
      if ('_party_ba_' == substr($key, 0, 10)) {
        if (!empty($value)) {
          $new_key = substr($key, 10);
          $account_info[$new_key] = $value;
        }
      } 
    }    

    // find all referenced bank accounts
    $bank_accounts = array();
    $contact_bank_account_id = NULL;
    $contact_bank_account_created = false;
    $reference2instances = array();
    foreach ($references as $reference_type => $reference) {
      $reference2instances[$reference] = array();
      $query = array('version'=>3, 'reference' => $reference, 'reference_type_id' => $reference_type);
      $existing = civicrm_api('BankingAccountReference', 'get', $query);
      if (empty($existing['is_error'])) {
        foreach ($existing['values'] as $account_reference) {
          array_push($reference2instances[$reference], $account_reference);
          if (!isset($bank_accounts[$account_reference['ba_id']])) {
            // load the bank account
            $ba_bao = new CRM_Banking_BAO_BankAccount();
            $ba_bao->get('id', $account_reference['ba_id']);
            $bank_accounts[$account_reference['ba_id']] = $ba_bao;
          }

          // consider this bank account to be ours if the contact id matches
          if (!$contact_bank_account_id && ($ba_bao->contact_id == $contact_id)) {
            $contact_bank_account_id = $ba_bao->id;
          }
        }
      }
    }

    // create new account if it does not yet exist
    if (!$contact_bank_account_id) {
      $ba_bao = new CRM_Banking_BAO_BankAccount();
      $ba_bao->contact_id = $contact_id;
      $ba_bao->description = ts("created by CiviBanking");
      $ba_bao->created_date = date('YmdHis');
      $ba_bao->modified_date = date('YmdHis');
      $ba_bao->data_raw = NULL;
      $ba_bao->data_parsed = "{}";
      $ba_bao->save();

      $contact_bank_account_id = $ba_bao->id;
      $bank_accounts[$contact_bank_account_id] = $ba_bao;
      $contact_bank_account_created = true;
    }

    // update bank account data
    $ba_bao = $bank_accounts[$contact_bank_account_id];
    $ba_data = $ba_bao->getDataParsed();
    foreach ($account_info as $key => $value) {
      $ba_data[$key] = $value;
    }
    $ba_bao->setDataParsed($ba_data);
    $ba_bao->save();

    // create references (warn if exists for another contact)
    foreach ($references as $reference_type => $reference) {
      // check the existing
      $reference_already_there = false;
      foreach ($reference2instances[$reference] as $reference_instance) {
        if ($reference_instance['ba_id'] == $contact_bank_account_id) {
          // there is already a reference for 'our' bank account
          $reference_already_there = true;
          break;
        }
      }

      if (!$reference_already_there) {
        // there was no reference to 'our' bank account -> create!
        $query = array( 'version'           => 3, 
                        'reference'         => $reference, 
                        'reference_type_id' => $reference_type,
                        'ba_id'             => $contact_bank_account_id);
        $result = civicrm_api('BankingAccountReference', 'create', $query);
        if (!empty($result['is_error'])) {
          CRM_Core_Session::setStatus(ts("Couldn't create reference. Error was: '%1'", array(1=>$result['error_message'])), ts('Error'), 'alert');
        }        
      }
    }

    // finally, create some feedback
    if ($contact_bank_account_created) {
      if (count($bank_accounts) > 1) {
        // there are mutiple acccounts referenced by this
        $message = ts("The account information of this contact was saved, but it is also used by the following contacts:<br/><ul>%s</ul>");
        $contacts = "";
        foreach ($bank_accounts as $ba_id => $ba_bao) {
          if ($ba_id == $contact_bank_account_id) continue;
          $contact = civicrm_api('Contact', 'getsingle', array('version' => 3, 'id' => $ba_bao->contact_id));
          if (empty($contact['is_error'])) {
            $url = CRM_Utils_System::url('civicrm/contact/view', 'cid='.$ba_bao->contact_id);
            $contacts .= "<li><a href='$url'>".$contact['display_name']."</a></li>";
          }
        }
        CRM_Core_Session::setStatus(sprintf($message, $contacts), ts('Warning'), 'warn');
      } else {
        CRM_Core_Session::setStatus(ts("The account information of this contact was saved."), ts('Account saved'), 'info');
      }     
    }
  }


  
  /**************************************************************
   *               Action-Based Execution                       *
   *        generic execution tools based on action objects.    *
   *            contact Paul.Delbar at delius.be                *
   *************************************************************/

  function translateAction($action,$params,$btx) {
    $className = 'CRM_Banking_PluginModel_Action_' . $action;
    if (class_exists($className)) {
      $actor = new $className();
      return $actor->describe($params,$btx);
    }
    return "Unknown action '{$action}'";      
  }
  
  function executeAction($action,$params,$btx,$match) {
    $className = 'CRM_Banking_PluginModel_Action_' . $action;
    if (class_exists($className)) {
      $actor = new $className();
      return $actor->execute($params,$btx,$match);
    }
  }
      
  function getActions( $btx ) {
      $config = $this->_plugin_config;
      $s = '';
      if (isset($config->actions)) {
        $s = '<ul>I suggest :';
        foreach ($config->actions as $action => $params) {
            $s .= '<li>' . $this->translateAction($action,$params,$btx) . '</li>';
        }
        $s .= '</ul>';
      }
      return $s;
    
  }

}

