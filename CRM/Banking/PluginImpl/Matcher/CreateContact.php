<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2013-2018 SYSTOPIA                       |
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
 * This matcher tries to create contacts before the other matchers are executed.
 */
class CRM_Banking_PluginImpl_Matcher_CreateContact extends CRM_Banking_PluginModel_Analyser {

  /**
   * class constructor
   */
  function __construct($config_name) {
    parent::__construct($config_name);
  }

  /**
   * This matcher does not really create suggestions, but rather creates
   * contacts.
   */
  public function analyse(CRM_Banking_BAO_BankTransaction $btx, CRM_Banking_Matcher_Context $context) {
    $config = $this->_plugin_config;

    $modified = FALSE;

    $data = $btx->getDataParsed();

    if (!empty($data['first_name']) && !empty($data['last_name']) && !empty($data['postal_code'])) {
      Civi::log()
        ->info("Trying to find matching contact...");

      $matches = 0;
      $contacts = \Civi\Api4\Contact::get(FALSE)
        ->addSelect('address_primary.*')
        ->addWhere('first_name', '=', $data['first_name'])
        ->addWhere('last_name', '=', $data['last_name'])
        ->addWhere('address_primary.postal_code', '=', $data['postal_code'])
        ->execute();
      foreach ($contacts as $contact) {
        // do something
        Civi::log()
          ->info("  Found  " . $data['first_name'] . " " . $data['last_name']);

        $existing_street_address = $contact['address_primary.street_address'];
        $new_street_address = $data['street_address'];

        if (empty($existing_street_address) || empty($new_street_address)) {
          continue;
        }

        $existing_street_address = $this->unify_address($existing_street_address);
        $new_street_address = $this->unify_address($new_street_address);

        Civi::log()
          ->info("comparing new [" . $new_street_address . "] with old [" . $existing_street_address . "]...");

        $distance = levenshtein($new_street_address, $existing_street_address);
        Civi::log()
          ->info("  l-dist:" . $distance);

        // todo depends on string length
        if ($distance < 2) {
          // contact exists
          $matches++;

          // check if banking info exists
          Civi::log()
            ->info("Search banking info for contact " . $contact['id']);

          $result = civicrm_api3('BankingAccount', 'get', [
            'contact_id' => $contact['id'],
          ]);
          Civi::log()
            ->info("  result: " . print_r($result, TRUE));

          if ($result['count'] === 0) {
            // create banking info
            $this->storeAccountWithContact($btx, $contact['id']);
            $modified = TRUE;
          }
        }
      }

      if ($matches === 0) {
        try {
          // create contact
          $contact = [
            'contact_type' => 'Individual',
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'address_primary.postal_code' => $data['postal_code'],
            'address_primary.street_address' => $data['street_address'],
            'address_primary.city' => $data['city'],
          ];

          $res = \Civi\Api4\Contact::create(FALSE)
            ->setValues($contact)
            ->execute()->first();
        }
        catch (Exception $e) {
          Civi::log('banking')->error(
            'Error creating contact: {msg}',
            ['%msg' => $e->getMessage()]);
        }
        if (!empty($res) && $res['id']) {
          Civi::log()
            ->info("created contact:  " . print_r($res, TRUE));
          // create banking info
          $this->storeAccountWithContact($btx, $res['id']);
          $modified = TRUE;
        }
      }
    }

    if ($modified) {
      // enrich the data for CreateContribution (financial_type_id is required)
      // geldspende - @todo get from db?
      $btx->financial_type_id = 1;
      $btx->save();
    }
  }

  /**
   * @param string $street_address
   *
   * @return string
   */
  protected function unify_address(string $street_address): string {
    $street_address = preg_replace('!str\.!i', 'straße', $street_address);
    $street_address = preg_replace('!strasse!i', 'straße', $street_address);

    setlocale(LC_ALL, 'de_DE');
    $street_address = iconv('UTF-8', 'ASCII//TRANSLIT', $street_address);

    // todo add more?
    return $street_address;
  }

}
