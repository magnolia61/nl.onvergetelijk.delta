<?php

require_once 'delta.civix.php';

use CRM_Delta_ExtensionUtil as E;

/**
 * HELPER: multi_implode
 * Functionele uitleg: Voegt geneste arrays samen tot een platte string.
 * Technische uitleg: Recursieve functie die door arrays loopt en ze plakt met de opgegeven glues.
 */
function multi_implode(array $glues, array $array) {
    $out = "";
    $g 	 = array_shift($glues);
    $c 	 = count($array);
    $i   = 0;
    
    foreach ($array as $val) {
        if (is_array($val)){
            $out .= multi_implode($glues, $val);
        } else {
            $out .= (string)$val;
        }
        $i++;
        if ($i < $c) {
            $out .= $g;
        }
    }
    
    return $out;
}

/**
 * HELPER: delta_calctabs
 * Functionele uitleg: Berekent de benodigde inspringing (tabs) op basis van de woordlengte.
 * Technische uitleg: Zorgt voor een strakke, verticale uitlijning in de e-mail weergave.
 */
function delta_calctabs($message) {
	$messagelenght = strlen($message);

	if ($messagelenght < 24 AND $messagelenght >= 16) {
		$tab = "\t";
	} elseif ($messagelenght < 16 AND $messagelenght >= 8) {
		$tab = "\t\t";
	} elseif ($messagelenght < 8) {
		$tab = "\t\t\t";
	} else {
		$tab = "\t";					
	}

	return $tab;
}

/**
 * HOOK: civicrm_pre
 * Functionele uitleg: Controleert op wijzigingen in de basisgegevens van een contact (Core).
 * Technische uitleg: Vergelijkt voornaam, achternaam, geslacht en geboortedatum vóór opslag.
 */
function delta_civicrm_pre($op, $objectName, $id, &$params) {
    static $processed_contacts = [];
    $extdebug = 4;

    if (!in_array($objectName, ['Individual', 'Contact'])) return;
    if (isset($processed_contacts[$id])) return;

    $contact_id      = $id;
    $create_activity = 0;
    $changes         = ['old' => [], 'new' => []];
    $deltacategory   = 'demografic';

    $result_contact = civicrm_api4('Contact', 'get', [
        'checkPermissions' => FALSE,
        'select' => ['display_name', 'first_name', 'middle_name', 'last_name', 'birth_date', 'gender_id', 'gender_id:label', 'DITJAAR.DITJAAR_kampkort'],
        'where' => [['id', '=', $contact_id]],
    ])->first();

    if (!$result_contact) return;

    // Velden om te controleren
    $fields = [
        'first_name'  => 'Voornaam', 
        'middle_name' => 'Tussenvoegsel',
        'last_name'   => 'Achternaam', 
        'gender_id'   => 'Geslacht',
        'birth_date'  => 'Geboortedatum'
    ];

    foreach ($fields as $key => $label) {
        if (isset($params[$key])) {
            $old_v = format_civicrm_smart($result_contact[$key] ?? '', $key);
            $new_v = format_civicrm_smart($params[$key], $key);

            // Speciale datum-check om syntax verschillen te negeren
            if ($key === 'birth_date') {
                $old_v = !empty($old_v) ? date('Y-m-d', strtotime($old_v)) : '';
                $new_v = !empty($new_v) ? date('Y-m-d', strtotime($new_v)) : '';
            }

            if ($old_v !== $new_v) {
                $create_activity = 1;
                $tab = delta_calctabs($label);
                
                $old_display = ($key === 'gender_id') ? ($result_contact['gender_id:label'] ?? $old_v) : $old_v;
                $new_display = ($key === 'gender_id') ? ($params[$key] == 1 ? 'meisje' : 'jongen') : $new_v;

                // FIX: Voeg een <br> direct toe aan de waarde om plakken te voorkomen
                $changes['old'][] = $label . $tab . ": " . ($old_display ?: '(leeg)');
                $changes['new'][] = $label . $tab . ": " . $new_display;
            }
        }
    }

    if ($create_activity == 1) {
        $processed_contacts[$id] = true;

        // FIX: Gebruik een gewone implode met <br> op de platte array
        $deltadetailsold = implode("<br>", $changes['old']);
        $deltadetailsnew = implode("<br>", $changes['new']);

        $activityparams_array = [
            'displayname'     => $result_contact['display_name'],
            'deltacategory'   => $deltacategory,
            'prioriteit'      => 'Urgent',
            'prioriteit_id'   => 1,
            'deltadetailsold' => $deltadetailsold,
            'deltadetailsnew' => $deltadetailsnew,
            'kampkort'        => $result_contact['DITJAAR.DITJAAR_kampkort'] ?? NULL,
        ];
        
        delta_activity_create($contact_id, $activityparams_array);
        wachthond($extdebug, 1, "### DELTA - CORE ACTIVITEIT GEMAAKT");
    }
}

/*
function delta_civicrm_pre(string $op, string $objectName, $id, array &$params): void {

	if (!$params['gender_id'] AND !$params['birth_date'] AND !$params['first_name'] AND !$params['last_name']) {
		return;
	}

 	$extdebug				= 4; // 1 = basic // 2 = verbose // 3 = params / 4 = results
    $deltacategory 			= '';

	$changes['old']			= [];
	$changes['new']			= [];	

	$contact_id 			= $params['contact_id'];

	wachthond($extdebug,1, "#############################################################");
	wachthond($extdebug,1, "### DELTA - CHANGE OF IMPORTANT CORE FIELDS",        "[START]");
	wachthond($extdebug,1, "#############################################################");

	$arraysize = sizeof($params);

	wachthond($extdebug,4, 'Pre params', 			$params);
	wachthond($extdebug,4, 'Pre params (size)', 	$arraysize);

  	if ($contact_id) {

		$params_contact = [
			'checkPermissions' => FALSE,
    		'limit' => 1,
			'select' => [
    			'id',
    			'contact_id',
    			'first_name',
    			'last_name',
    			'display_name',
    			'job_title',
    			'external_identifier',
    			'birth_date',
    			'gender_id',
    			'gender_id:label',
    			'DITJAAR.DITJAAR_cid',
    			'DITJAAR.DITJAAR_pid',
    			'DITJAAR.DITJAAR_eid',
				'DITJAAR.DITJAAR_rol',
    			'DITJAAR.DITJAAR_functie',
    			'DITJAAR.DITJAAR_kampkort',
    			'DITJAAR.DITJAAR_kampjaar',
    			'DITJAAR.DITJAAR_event_start',
    			'DITJAAR.DITJAAR_event_end',
			],
  			'where' => [
    			['id', '=', $contact_id],
  			],
		];

		wachthond($extdebug,3, 'params_contactinfo', 		$params_contact);
		$result_contact = civicrm_api4('Contact', 'get', 	$params_contact);
		wachthond($extdebug,3, 'result_contactinfo', 		$result_contact);

		$displayname		= $result_contact[0]['display_name'] 				?? NULL;
 		$org_firstname		= $result_contact[0]['first_name'] 					?? NULL;
 		$org_lastname		= $result_contact[0]['last_name'] 					?? NULL; 		
 		$org_birthdate		= $result_contact[0]['birth_date'] 					?? NULL;
 		$org_genderid		= $result_contact[0]['gender_id'] 					?? NULL;
 		$org_genderidlabel	= $result_contact[0]['gender_id:label'] 			?? NULL;

 		$actcontact_cid		= $result_contact[0]['DITJAAR.DITJAAR_cid'] 		?? NULL;
 		$actcontact_pid		= $result_contact[0]['DITJAAR.DITJAAR_pid'] 		?? NULL;
 		$actkampkort 		= $result_contact[0]['DITJAAR.DITJAAR_kampkort'] 	?? NULL;
 		$actkamprol			= $result_contact[0]['DITJAAR.DITJAAR_rol'] 		?? NULL;
 		$actkampfunctie		= $result_contact[0]['DITJAAR.DITJAAR_functie'] 	?? NULL;
 		$actkampjaar 		= $result_contact[0]['DITJAAR.DITJAAR_kampjaar'] 	?? NULL;
 		$acteventstart 		= $result_contact[0]['DITJAAR.DITJAAR_event_start'] ?? NULL;
 		$acteventeinde 		= $result_contact[0]['DITJAAR.DITJAAR_event_end'] 	?? NULL;
		$acteventstart		= date('Y-m-d H:i:s', strtotime($acteventstart));
		$acteventeinde		= date('Y-m-d H:i:s', strtotime($acteventeinde));
		$actkampkort_low	= preg_replace('/[^ \w-]/','',strtolower(trim($actkampkort)));	// keep only letters, numbers & dashes
		$actkampkort_cap 	= preg_replace('/[^ \w-]/','',strtoupper(trim($actkampkort)));	// keep only letters, numbers & dashes

		wachthond($extdebug,3, 'contact_id', 				$contact_id);
		wachthond($extdebug,3, 'displayname', 				$displayname);
 	}

  	if ($params['gender_id']) {

		$customFieldName 	= "Geslacht";
		$deltacategory 		= 'demografic';
  		$new_genderid		= $params['gender_id'];
  		if ($new_genderid == 1) {
			$new_genderidlabel = 'meisje';
  		} else {
			$new_genderidlabel = 'jongen'; 			
  		}

 		if ($org_genderid 	!= $new_genderid AND !empty($org_genderid)) {
			$tab 				= delta_calctabs($customFieldName);
			$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $org_genderidlabel;
			$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $new_genderidlabel;
			wachthond($extdebug,3, "changes['new']", 	$changes['new']);
	 	}

		wachthond($extdebug,2, 'org_genderid', 		$org_genderid);	
		wachthond($extdebug,2, 'new_genderid', 		$new_genderid);

		wachthond($extdebug,2, 'org_genderidlabel', $org_genderidlabel);	
		wachthond($extdebug,2, 'new_genderidlabel', $new_genderidlabel);

  	}

  	if ($params['birth_date']) {

  		$customFieldName 	= "Geboortedatum";
  		$deltacategory 		= 'demografic';
		$raw_birthdate 		= strtotime($params['birth_date']);
        $new_birthdate  	= date("Y-m-d", $raw_birthdate); 

	 	if ($org_birthdate 	!= $new_birthdate	AND !empty($org_birthdate)) {
			$tab 			= delta_calctabs($customFieldName);
			$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $org_birthdate;
			$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $new_birthdate;
			wachthond($extdebug,3, "changes['new']", 	$changes['new']);       
 		}

		wachthond($extdebug,2, 'org_birthdate', 	$org_birthdate);
		wachthond($extdebug,2, 'new_birthdate', 	$new_birthdate);

  	}

  	if ($params['first_name']) {

		$customFieldName 	= "Voornaam";
		$deltacategory 		= 'demografic';
  		$new_firstname		= trim($params['first_name']);

 		if ($org_firstname 	!= $new_firstname) {
			$tab 			= delta_calctabs($customFieldName);
			$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $org_firstname;
			$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $new_firstname;
			wachthond($extdebug,3, "changes['new']", 	$changes['new']);
	 	}

		wachthond($extdebug,2, 'org_firstname', 	$org_firstname);	
		wachthond($extdebug,2, 'new_firstname', 	$new_firstname);

  	}

  	if ($params['last_name']) {

		$customFieldName 	= "Achternaam";
		$deltacategory 		= 'demografic';
  		$new_lastname		= trim($params['last_name']);

 		if ($org_lastname 	!= $new_lastname) {
			$tab 			= delta_calctabs($customFieldName);
			$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $org_lastname;
			$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $new_lastname;
			wachthond($extdebug,3, "changes['new']", 	$changes['new']);
	 	}

		wachthond($extdebug,2, 'org_lastname', 	$org_lastname);	
		wachthond($extdebug,2, 'new_lastname', 	$new_lastname);

  	}

	$glues 					= array('<br>','<br />','<BR>');

	if (!empty($changes['old'])) {
		$changes['old'] 	= array_filter($changes['old']);
	}
	if (!empty($changes['new'])) {
		$changes['new'] 	= array_filter($changes['new']);
	}

	$cnt_changes_old   		= sizeof($changes['old']);
	$cnt_changes_new   		= sizeof($changes['new']);	
	wachthond($extdebug,3, "cnt_changes_old",      $cnt_changes_old);
	wachthond($extdebug,3, "cnt_changes_new",      $cnt_changes_new);	

	if (!empty($changes['old'])) {
		$deltadetailsold 	= multi_implode($glues,$changes['old']);
	}
	if (!empty($changes['new'])) {
		$deltadetailsnew 	= multi_implode($glues,$changes['new']);
	}

	wachthond($extdebug,2, "changes['old']", 	$changes['old']);
	wachthond($extdebug,2, "changes['new']", 	$changes['new']);

	wachthond($extdebug,4, 'Core Pre params', 		$params);

 	if ($org_birthdate 	!= $new_birthdate) {
 		$create_activity = 1;					# create activity delta
 	}
 	if ($org_genderid 	!= $new_genderid) {
 		$create_activity = 1;					# create activity delta 	
 	}
 	if ($org_firstname 	!= $new_firstname) {
 		$create_activity = 1;					# create activity delta 	
 	}
 	if ($org_lastname 	!= $new_lastname) {
 		$create_activity = 1;					# create activity delta 	
 	}

 	if ($create_activity == 1) {

		$prioriteit 	= 'Urgent';
		$prioriteit_id 	= 1;

		$activityparams_array = array(
			'displayname'			=> $displayname,
			'deltacategory'			=> $deltacategory,
	    	'prioriteit'          	=> $prioriteit,
	    	'prioriteit_id'        	=> $prioriteit_id,
	        'deltadetailsold'     	=> $deltadetailsold,
	        'deltadetailsnew'     	=> $deltadetailsnew,
		);

		if ($contact_id AND $activityparams_array) {
			delta_activity_create($contact_id, $activityparams_array);
		}
	}

	wachthond($extdebug,1, "#############################################################");
	wachthond($extdebug,1, "### DELTA - CHANGE OF IMPORTANT CORE FIELDS",        "[EINDE]");
	wachthond($extdebug,1, "#############################################################");

}
*/

/*
function delta_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

  	if ($params[0]['entity_table'] == 'civicrm_participant') 						{
		return; //	if not, get out of here
  	}

 	$extdebug				= 3; // 1 = basic // 2 = verbose // 3 = params / 4 = results
    $deltacategory 			= '';

    $profilecont            = array(225);
    $profilecontintake      = array(181);

    $profilepartdeel        = array(139);
    $profilepartleid        = array(190);
    $profilepart_leidintern = array(300);

    $profilepartref         = array(213);
    $profilepartvog         = array(140);
    $profilepart            = array_merge($profilepartdeel, $profilepartleid);
    $profilepartintake      = array_merge($profilepartref,  $profilepartvog);

    if (in_array($groupID, $profilecontintake))  {
        $contact_id = $entityID;
	}

	// M61: ONLY CONTINUE IF ENTITY IS CONTACT [SKIP PART]

	if ($params[0]['entity_table'] == 'civicrm_contact') {
		$contact_id = $entityID;
	} else {
		return; //	if not, get out of here
	}

 	wachthond($extdebug,4, "##########################################################");
 	wachthond($extdebug,4, "### DELTA - NOTIFICATIONS OF IMPORTANT CHANGES [START] ###");
 	wachthond($extdebug,4, "###########################################################");

	wachthond($extdebug,4, 'customPre params', 			$params);

  	if ($params[0]['column_name'] == 'ditjaar_voorkeur_1208') 						{
  		$deltacategory = 'voorkeur';
//		wachthond($extdebug,1, "deltacategory", 					$deltacategory);
  	}

  	if ($params[0]['column_name'] == 'ditjaar_fietshuur_1173') 						{
  		$deltacategory = 'fietshuur';
  	}

//  	if ($params[0]['table_name'] 	== 'civicrm_value_ditjaar_199') 				{
//   		$deltacategory = 'ditjaar';
//			wachthond($extdebug,1, "params customPre", 					$params);
//  	}

  	if ($params[0]['table_name'] 	== 'civicrm_value_gedrag_322') 		{	$deltacategory = 'gedrag';	}
  	if ($params[0]['table_name'] 	== 'civicrm_value_medisch_148') 	{	$deltacategory = 'medisch';	}
  	if ($params[0]['table_name'] 	== 'civicrm_value_drijfveren_69') 	{	$deltacategory = 'bio';		}
  	if ($params[0]['table_name'] 	== 'civicrm_value_talent_149') 		{	$deltacategory = 'talent';	}

//	wachthond($extdebug,1, "deltacategory", 						$deltacategory);
	#$keys = array_keys(array_combine(array_keys($params), array_column($params, 'table_name')),'civicrm_value_aandachtspunten_gedrag_1');
//	wachthond($extdebug,1, "keys met gedrag", 						$keys);

  	if (in_array($deltacategory, array('gedrag','medisch','bio','fietshuur','voorkeur'))) {

	 	wachthond($extdebug,3, "#############################################################");
	 	wachthond($extdebug,2, "### DELTA - NOTIFICATIONS OF IMPORTANT CHANGES [START]    ###");
	 	wachthond($extdebug,3, "#############################################################");

	 	wachthond($extdebug,3, "#############################################################");
	 	wachthond($extdebug,2, "### DELTA - ONLY GEDRAG/MEDISCH/BIO/TALENT/FIETS/VOORKEUR ###");
	 	wachthond($extdebug,3, "#############################################################");

	 	wachthond($extdebug,4, 'customPre params', 			$params);
	 	wachthond($extdebug,3, 'params[0][entity_table]', 	$params[0]['entity_table']);

  		foreach ($params as $field) {

			$oldarray 	= [];
			$newarray 	= [];
			$oldvalue 	= NULL;
			$newvalue 	= NULL;
			$oldflat  	= NULL;
			$newflat  	= NULL;

			$fieldid	= $field['custom_field_id'] ?? NULL;
			$fieldname	= $field['column_name'] 	?? NULL;
  			$fieldvalue	= $field['value'] 			?? NULL;

			$newvalue 	= $fieldvalue;

			wachthond($extdebug,4, "field", 		$field);
			wachthond($extdebug,4, "fieldid", 		$fieldid);
			wachthond($extdebug,4, "fieldname", 	$fieldname);
			wachthond($extdebug,4, "fieldvalue",	$fieldvalue);

			###############################################################################
			### SKIP SOME FIELDS FROM BEING CONSIDERED
			###############################################################################

			if (str_contains($fieldname, 'modified')) {

			 	wachthond($extdebug,3, "#############################################################");
			 	wachthond($extdebug,5, "### DELTA - NOTIFICATIONS OF IMPORTANT CHANGES [START] ###");
			 	wachthond($extdebug,5, "###########################################################");

				wachthond($extdebug,5, "SKIP DELTA voor $entityID WANT fieldname:", $fieldname);

			 	wachthond($extdebug,3, "#############################################################");
			 	wachthond($extdebug,5, "### DELTA - NOTIFICATIONS OF IMPORTANT CHANGES [EINDE] ###");
			 	wachthond($extdebug,5, "###########################################################");

				return;
			}

			###############################################################################
			### PROCESS OLD & NEW VALUES - STAP 1: NORMALISATIE (PHP 8.3 FIX)
			###############################################################################

			// 1. Haal de oude waarde op uit de database
			$oldvalue = civicrm_api3('Contact', 'getvalue', [
				'id' => $contact_id,
				'return' => "custom_{$fieldid}",
			]);

			// --- EXTRA DEBUG: Ruwe waarden voor normalisatie ---
	        wachthond($extdebug, 4, "DEBUG: Ruwe data voor $fieldname", [
	            'DB_old' => $oldvalue,
	            'FORM_new' => $newvalue
	        ]);

			// 2. Gebruik de helper uit de base module om beide waarden te normaliseren.
			// Dit zorgt dat vinkjes (arrays) en datums altijd als dezelfde tekst worden vergeleken.
			$old_normalized = format_civicrm_smart($oldvalue, $fieldname);
			$new_normalized = format_civicrm_smart($newvalue, $fieldname);

			// --- EXTRA DEBUG: Geformatteerde waarden die vergeleken gaan worden ---
	        wachthond($extdebug, 3, "DEBUG: Vergelijking voor $fieldname", [
	            'Normalized_old' => $old_normalized,
	            'Normalized_new' => $new_normalized
	        ]);			

			// 3. Vul de variabelen voor de vergelijking en de activiteit
			// We halen de technische CiviCRM-tekens () weg voor de leesbaarheid
			$oldflat 		= str_replace("\x01", " / ", trim($old_normalized, "\x01"));
			$newflat 		= str_replace("\x01", " / ", trim($new_normalized, "\x01"));

			// 4. De eigenlijke check: Alleen doorgaan als er ÉCHT een verschil is
			if ($oldflat != $newflat) {

				// Alleen als dit blok wordt uitgevoerd, wordt er een activiteit aangemaakt
            	wachthond($extdebug, 2, "MATCH: Verschil gedetecteerd voor $fieldname! Activiteit wordt voorbereid.");				

			 	wachthond($extdebug,3, "##########################################################");
			 	wachthond($extdebug,3, "### DELTA - $fieldid IMPORTANT DETECTION","[FIELD CHANGED]");
			 	wachthond($extdebug,3, "##########################################################");

				wachthond($extdebug,4, "oldvalue 0 $oldvaluetype", 				$oldvalue);
				wachthond($extdebug,2, "OLDFLAT  0 $oldvaluetype", 				$oldflat);
				wachthond($extdebug,4, "newvalue 0 $newvaluetype", 				$newvalue);
				wachthond($extdebug,2, "NEWFLAT  0 $newvaluetype", 				$newflat);

				wachthond($extdebug,2, "field", 		$field);
				wachthond($extdebug,5, "fieldid", 		$fieldid);
				wachthond($extdebug,5, "fieldname", 	$fieldname);
				wachthond($extdebug,5, "fieldvalue",	$fieldvalue);

  				##############################
  				#### OLDVALUE ARRAY ####
  				##############################

				$customFields = civicrm_api4('CustomField', 'get', [
				   'select' => [
				    	'label',
				    	'data_type',
				    	'html_type',
				    	'option_group_id'
  					],
  					'where' => [
   						['id', '=', $fieldid],
 					],
  					'limit' => 1,
  					'checkPermissions' => FALSE,
				]);
				$customFieldName 		= $customFields[0]['label'] 			?? NULL;
				$customFieldDataType	= $customFields[0]['data_type'] 		?? NULL;
				$customFieldHtmlType	= $customFields[0]['html_type'] 		?? NULL;
				$customFieldOptionGroup = $customFields[0]['option_group_id'] 	?? NULL;

				$messagelenght			= strlen($customFieldName);
				$prioriteit 			= 'Normaal'; // default

				###############################################################################
				### AANDACHTSPUNTEN MEDISCH
				###############################################################################

				if ($customFieldName == 'medisch_medicatie') 	{
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;				
				}
				if ($customFieldName == 'medisch_toelichting ') {
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}

				// M61: TODO
				// BEPALEN WAT TE DOEN BIJ AANPASSING VAN [intern] VELD DOUBLECHECK

				###############################################################################
				### AANDACHTSPUNTEN GEDRAG
				###############################################################################

				if ($customFieldName == 'gedrag_shortlist') 	{
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}
				if ($customFieldName == 'gedrag_toelichting') 	{
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}

				###############################################################################
				### AANDACHTSPUNTEN DIEET
				###############################################################################

				if ($customFieldName == 'dieet_shortlist') 		{
					$deltacategory	= 'dieet';
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}							
				if ($customFieldName == 'dieet_toelichting') 	{
					$deltacategory	= 'dieet';
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}							

				###############################################################################
				### AANDACHTSPUNTEN DITJAAR / LASTMINUTE
				###############################################################################

				if ($customFieldName == 'ditjaar_groep_klas') 	{
					$deltacategory	= 'ditjaar';
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}
				if ($customFieldName == 'ditjaar_voorkeur') 	{
					$deltacategory	= 'ditjaar';
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}
				if ($customFieldName == 'ditjaar_fietshuur') 	{
					$deltacategory	= 'ditjaar';
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}

				// M61: TODO
				// ALLEEN NOTIFICATIE GROEP/KLAS INDIEN VOORHEEN AL INGEVULD. DUS OPVALLENDE WIJZIGING
				// ALLEEN NOTIFICATIE VOORKEUR / FIETSHUUR INDIEN IN LAATSTE PERIODE VOOR KAMP

				###############################################################################
				### AANDACHTSPUNTEN BIO / DRIJFVEREN
				###############################################################################

				if ($customFieldName == 'Ben je christen?') 		{
					$deltacategory	= 'bio';
					$prioriteit 	= 'Urgent';
					$prioriteit_id 	= 1;
				}

				wachthond($extdebug,4, "##############################################################");
				wachthond($extdebug,2, "### customField [OUD] Name", 		$customFieldName);
				wachthond($extdebug,2, "### customField [OUD] DataType", 	$customFieldDataType);
				wachthond($extdebug,2, "### customField [OUD] HtmlType", 	$customFieldHtmlType);
				wachthond($extdebug,2, "### customField [OUD] OptionGroup",	$customFieldOptionGroup);
				wachthond($extdebug,4, "##############################################################");
				wachthond($extdebug,4, "oldvalue 1 ($oldvaluetype)", $oldvalue);
				wachthond($extdebug,4, "newvalue 1 ($newvaluetype)", $newvalue);
				wachthond($extdebug,4, "##############################################################");

				if ($messagelenght       < 24 AND $messagelenght >= 16) {
					$tab = "\t";
				} elseif ($messagelenght < 16 AND $messagelenght >= 8) {
					$tab = "\t\t";
				} elseif ($messagelenght < 8) {
					$tab = "\t\t\t";
				} else {
					$tab = "\t";					
				}				

				###############################################################################
				### [OLD] BOOLEAN
				###############################################################################

				if (in_array($customFieldDataType, array("Boolean"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA OLD - $fieldid DATA TYPE: $customFieldDataType", "[HTML: BOOLEAN]");
					wachthond($extdebug,3, "##############################################################");

					if (empty($oldvalue))	{ $oldvalue = '(was leeg)'; 	}
					if ($oldvalue == '1') 	{ $oldvalue = 'ja';  			}
					if ($oldvalue == '0') 	{ $oldvalue = 'nee'; 			}					

					wachthond($extdebug,2, "OLDVALUE (boolean)", 	$oldvalue);
					$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $oldvalue;

				###############################################################################
				### [OLD] DATE
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("Date"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA OLD - $fieldid DATA TYPE: $customFieldDataType",    "[HTML: DATE]");
					wachthond($extdebug,3, "##############################################################");

					wachthond($extdebug,2, "OLDVALUE (date)", 		$oldvalue);
					$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $oldvalue;

				###############################################################################
				### [OLD] TEXTAREA
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("Text","TextArea"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA OLD - $fieldid DATA TYPE: $customFieldDataType","[HTML: TEXTAREA]");
					wachthond($extdebug,3, "##############################################################");

					if (empty($oldvalue))	{ $oldvalue = '(was leeg)'; 	}
					wachthond($extdebug,2, "OLDVALUE (string)", 	$oldvalue);
					$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $oldvalue;

				###############################################################################
				### [OLD] SELECT / RADIO
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("Radio", "Select"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA OLD - $fieldid DATA TYPE: $customFieldDataType","[HTML: RADIO/SELECT]");
					wachthond($extdebug,3, "##############################################################");				

					$params_optionvalues = [
						'checkPermissions' => FALSE,
							'select' => [
								'name', 'label',
							],
							'where' => [
								['value', 			'=', $oldvalue], 
								['option_group_id', '=', $customFieldOptionGroup]
							],
					];
					wachthond($extdebug,5,	'params_optionvalues', 			$params_optionvalues);
					$result_optionvalues = civicrm_api4('OptionValue','get',$params_optionvalues);
					wachthond($extdebug,5,	'result_optionvalues', 			$result_optionvalues);

					$optionlabel = $result_optionvalues[0]['label'] ?? NULL;
					wachthond($extdebug,3, "optionlabel", 		$optionlabel);
					$oldarraylabels[$deltacategory][$customFieldName][] = $optionlabel;
					wachthond($extdebug,3, "oldarraylabels", 	$oldarraylabels);

					if (is_array($oldarraylabels)) {
						$oldarraystring[$deltacategory][$customFieldName] = implode(" / ",$oldarraylabels[$deltacategory][$customFieldName]);
					}

					wachthond($extdebug,3, "OLDVALUE (radioselect)", 	$oldarraystring[$deltacategory][$customFieldName]);

					$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $oldarraystring[$deltacategory][$customFieldName];

				###############################################################################
				### [OLD] CHECKBOX (let op CheckBox als type is met hoofdletter B)
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("CheckBox"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA OLD - $fieldid DATA TYPE: $customFieldDataType","[HTML: CHECKBOX]");
					wachthond($extdebug,3, "##############################################################");

					wachthond($extdebug,3, "oldarray", 	$oldarray);

					foreach ($oldarray as $optionvalue) {

						wachthond($extdebug,3, "optionvalue", 	$optionvalue);

						$params_optionvalues = [
							'checkPermissions' => FALSE,
  							'select' => [
    							'name', 'label',
  							],
  							'where' => [
								['value', 			'=', $optionvalue], 
    							['option_group_id', '=', $customFieldOptionGroup]
  							],
						];
						wachthond($extdebug,5,	'params_optionvalues', 			$params_optionvalues);
						$result_optionvalues = civicrm_api4('OptionValue','get',$params_optionvalues);
						wachthond($extdebug,5,	'result_optionvalues', 			$result_optionvalues);

						$optionlabel = $result_optionvalues[0]['label'] ?? NULL;
						wachthond($extdebug,3, "optionlabel", 		$optionlabel);
   						$oldarraylabels[$deltacategory][$customFieldName][] = $optionlabel;
						wachthond($extdebug,3, "oldarraylabels", 	$oldarraylabels);

						if (is_array($oldarraylabels)) {
							$oldarraystring[$deltacategory][$customFieldName] 
							= implode(" / ",$oldarraylabels[$deltacategory][$customFieldName]);
						}
						wachthond($extdebug,2, "OLDVALUE (flatarray)", 	
						$oldarraystring[$deltacategory][$customFieldName]);
						$newoptionlabel = $oldarraystring[$deltacategory][$customFieldName];
						$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $newoptionlabel;
					}

				} else {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA OLD - $fieldid DATA TYPE: $customFieldDataType",   "[HTML: OTHER]");
					wachthond($extdebug,3, "##############################################################");

					wachthond($extdebug,3, 	"### customField htmltype",	$customFieldHtmlType);

					if (empty($oldarray))	{

						$changes['old'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". '(was leeg)';
						wachthond($extdebug,3, "oldvalue", 	"[was leeg]");

					}

				}

  				##############################
  				#### NEWVALUE ARRAY ####
  				##############################

				$customFields = civicrm_api4('CustomField', 'get', [
					'select' => [
				    	'label',
				    	'data_type',
				    	'html_type',
				    	'option_group_id'
  					],
  					'where' => [
   						['id', '=', $fieldid],
 					],
  					'limit' => 1,
  					'checkPermissions' => FALSE,
				]);

				$customFieldName 		= $customFields[0]['label'] 			?? NULL;
				$customFieldDataType	= $customFields[0]['data_type'] 		?? NULL;
				$customFieldHtmlType	= $customFields[0]['html_type'] 		?? NULL;
				$customFieldOptionGroup = $customFields[0]['option_group_id'] 	?? NULL;
				$messagelenght			= strlen($customFieldName);

				wachthond($extdebug,5, 	"customFieldName (new) $customFieldName",
										$oldarraystring[$deltacategory][$customFieldName]);
				wachthond($extdebug,5, 	"customFieldName (new) $customFieldName",
										$oldarraystring[$deltacategory][$customFieldName]);

				wachthond($extdebug,3, 	"### customField [NEW] name",		$customFieldName);
				wachthond($extdebug,3, 	"### customField [NEW] datatype",	$customFieldDataType);
				wachthond($extdebug,3, 	"### customField [NEW] htmltype",	$customFieldHtmlType);

				if (empty($newvalue))	{ $newvalue = '(leeggemaakt)'; 	}

				###############################################################################
				### [NEW] BOOLEAN
				###############################################################################

				if (in_array($customFieldDataType, array("Boolean"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA NEW - $fieldid DATA TYPE: $customFieldDataType","[HTML: BOOLEAN]");
					wachthond($extdebug,3, "##############################################################");

					if ($newvalue == '1') 	{ $newvalue = 'ja';  			}
					if ($newvalue == '0') 	{ $newvalue = 'nee'; 			}

					wachthond($extdebug,2, 	"NEWVALUE (boolean)",	$newvalue);

					$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $newvalue;

				###############################################################################
				### [NEW] DATE
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("Date"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA NEW - $fieldid DATA TYPE: $customFieldDataType","[HTML: DATE]");
					wachthond($extdebug,3, "##############################################################");

					if ($newdate == "1970-01-01 01:00:00") {
						$newdate =  '(leeggemaakt)';
					}
					wachthond($extdebug,2, 	"NEWVALUE (date)",	$newdate);
					$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $newdate;

				###############################################################################
				### [NEW] TEXTAREA
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("Text","TextArea"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA NEW - $fieldid DATA TYPE: $customFieldDataType","[HTML: TEXTAREA]");
					wachthond($extdebug,3, "##############################################################");

					wachthond($extdebug,2, 	"NEWVALUE (string)",	$newvalue);
					$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". $newvalue;

				###############################################################################
				### [NEW] RADIO
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("Radio", "Select"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA NEW - $fieldid DATA TYPE: $customFieldDataType","[HTML: RADIOSELECT]");
					wachthond($extdebug,3, "##############################################################");				

					$params_optionvalues = [
						'checkPermissions' => FALSE,
							'select' => [
							'name', 'label',
							],
							'where' => [
							['value', 			'=', $newvalue], 
							['option_group_id', '=', $customFieldOptionGroup]
							],
					];
					wachthond($extdebug,5,'params_optionvalues', 			$params_optionvalues);
					$params_optionvalues = civicrm_api4('OptionValue','get',$params_optionvalues);
					wachthond($extdebug,5,'result_optionValues', 			$result_optionvalues);

					$optionlabel = $params_optionvalues[0]['label'] ?? NULL;
					wachthond($extdebug,5, "optionlabel",		$optionlabel);
						$newarraylabels[$deltacategory][$customFieldName][] = $optionlabel;
					wachthond($extdebug,5, "newarraylabels",	$newarraylabels);

					if (is_array($newarraylabels)) {

						$newarraystring[$deltacategory][$customFieldName] 
						= implode(" / ",$newarraylabels[$deltacategory][$customFieldName]);

						wachthond($extdebug,5, 	"newarraystring $deltacategory",	
											$newarraystring[$deltacategory][$customFieldName]);
					}

					wachthond($extdebug,2, 	"NEWVALUE (radioselect)",	
											$newarraystring[$deltacategory][$customFieldName]);
					$changes['new'][$deltacategory][$customFieldName]
					= $customFieldName .$tab.": ". $newarraystring[$deltacategory][$customFieldName];

				###############################################################################
				### [new] CHECKBOX
				###############################################################################

				} elseif (in_array($customFieldHtmlType, array("CheckBox"))) {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA NEW - $fieldid DATA TYPE: $customFieldDataType","[HTML: CHECKBOX]");
					wachthond($extdebug,3, "##############################################################");

					// M61: TODO URGENT 
					// ValueError: explode(): Argument #1 ($separator) cannot be empty in explode() (line 526 of /var/www/vhosts/ozkprod/web/sites/all/modules/civicrm_extensions/nl.onvergetelijk.delta/delta.php).

					// M61: uitvogelen waarom dit hier nodig is. Een explode waabij de seperator leeg is lijkt ook niet logisch

					$newarray 	= explode (' ', $field['value']);
//					$newarray 	= $field['value'];

					if (!empty($newflat))	{
						$newarray	= array_filter($newarray);
					}
					wachthond($extdebug,3, "newarray 1",	$newarray);

					foreach ($newarray as $optionvalue) {

						wachthond($extdebug,3, "optionvalue", 	$optionvalue);

						$params_optionvalues = [
							'checkPermissions' => FALSE,
  							'select' => [
    							'name', 'label',
  							],
  							'where' => [
								['value', 			'=', $optionvalue], 
    							['option_group_id', '=', $customFieldOptionGroup]
  							],
						];
						wachthond($extdebug,5,	'params_optionvalues', 			$params_optionvalues);
						$result_optionvalues = civicrm_api4('OptionValue','get',$params_optionvalues);
						wachthond($extdebug,5,	'result_optionvalues', 			$result_optionvalues);

						$optionlabel = $result_optionvalues[0]['label'] ?? NULL;
						wachthond($extdebug,3, "optionlabel", 		$optionlabel);
   						$newarraylabels[$deltacategory][$customFieldName][] = $optionlabel;
						wachthond($extdebug,3, "newarraylabels", 	$newarraylabels);

						if (is_array($newarraylabels)) {
							$newarraystring[$deltacategory][$customFieldName] = implode(" / ",$newarraylabels[$deltacategory][$customFieldName]);
								wachthond($extdebug,5, "newarraystring $deltacategory", 	
										  $newarraystring[$deltacategory][$customFieldName]);
						}
						wachthond($extdebug,2, "NEWVALUE (flatarray)",		
										$newarraystring[$deltacategory][$customFieldName]);

						if (empty($newflat))	{
							$changes['new'][$deltacategory][$customFieldName]
							= $customFieldName .$tab.": ". '(leeggemaakt)';
						} else {
							$changes['new'][$deltacategory][$customFieldName]
							= $customFieldName .$tab.": ". $newarraystring[$deltacategory][$customFieldName];
						}

					}

				###############################################################################
				### [NEW] OTHER
				###############################################################################

				} else {

					wachthond($extdebug,3, "##############################################################");
				 	wachthond($extdebug,3, "### DELTA NEW - $fieldid DATA TYPE: $customFieldDataType",   "[HTML: OTHER]");
					wachthond($extdebug,3, "##############################################################");

					wachthond($extdebug,3, 	"### customField htmltype",	$customFieldHtmlType);

					if (empty($newarray))	{

						$changes['new'][$deltacategory][$customFieldName] = $customFieldName .$tab.": ". '(was leeg)';
						wachthond($extdebug,3, "newvalue", 	"[was leeg]");

					}
				}
			}
   		}

		if (!empty($changes['old']) AND !empty($changes['new'])) {	

			wachthond($extdebug,2, "changes['old']", 	$changes['old']);
			wachthond($extdebug,2, "changes['new']", 	$changes['new']);

			$glues 					= array('<br>','<br />','<BR>');

			$changes['old'] 		= array_filter($changes['old']);
			$changes['new'] 		= array_filter($changes['new']);

			if (!empty($changes['old'])) {
	  			$deltadetailsold 	= multi_implode($glues,$changes['old']);
			}
			if (!empty($changes['new'])) {
	  			$deltadetailsnew 	= multi_implode($glues,$changes['new']);
			}

			wachthond($extdebug,2, "changes['old']", 	$changes['old']);
			wachthond($extdebug,2, "changes['new']", 	$changes['new']);

			wachthond($extdebug,2, "################################################################");
			wachthond($extdebug,1, "!!! CHANGED velden $deltacategory [OUD]", $deltadetailsold);
			wachthond($extdebug,1, "!!! CHANGED velden $deltacategory [NEW]", $deltadetailsnew);
			wachthond($extdebug,2, "################################################################");

			$params_contact = [
				'checkPermissions' => FALSE,
	    		'limit' => 1,
				'select' => [
	    			'id',
	    			'contact_id',
	    			'first_name',
	    			'display_name',
	    			'job_title',
	    			'external_identifier',
	    			'DITJAAR.DITJAAR_cid',
	    			'DITJAAR.DITJAAR_pid',
	    			'DITJAAR.DITJAAR_eid',
					'DITJAAR.DITJAAR_rol',
	    			'DITJAAR.DITJAAR_functie',
	    			'DITJAAR.DITJAAR_kampkort',
	    			'DITJAAR.DITJAAR_kampjaar',
	    			'DITJAAR.DITJAAR_event_start',
	    			'DITJAAR.DITJAAR_event_end',
				],
	  			'where' => [
	    			['id', '=', $entityID],
	  			],
			];

			// M61 TODO: de query hierboven beter basseren op participant data, en dat huidig fiscale jaar

			wachthond($extdebug,5, 'params_contactinfo', 		$params_contact);
			$result_contact = civicrm_api4('Contact', 'get', 	$params_contact);
			wachthond($extdebug,5, 'result_contactinfo', 		$result_contact);

	 		$actdisplayname		= $result_contact[0]['display_name'] 				?? NULL;
	// 		$actcontact_cid		= $result_contact[0]['contact_id'] 					?? NULL;
	 		$actcontact_cid		= $result_contact[0]['DITJAAR.DITJAAR_cid'] 		?? NULL;
	 		$actcontact_pid		= $result_contact[0]['DITJAAR.DITJAAR_pid'] 		?? NULL;
	 		$actkampkort 		= $result_contact[0]['DITJAAR.DITJAAR_kampkort'] 	?? NULL;
	 		$actkamprol			= $result_contact[0]['DITJAAR.DITJAAR_rol'] 		?? NULL;
	 		$actkampfunctie		= $result_contact[0]['DITJAAR.DITJAAR_functie'] 	?? NULL;
	 		$actkampjaar 		= $result_contact[0]['DITJAAR.DITJAAR_kampjaar'] 	?? NULL;
	 		$acteventstart 		= $result_contact[0]['DITJAAR.DITJAAR_event_start'] ?? NULL;
	 		$acteventeinde 		= $result_contact[0]['DITJAAR.DITJAAR_event_end'] 	?? NULL;
			$acteventstart		= date('Y-m-d H:i:s', strtotime($acteventstart));
			$acteventeinde		= date('Y-m-d H:i:s', strtotime($acteventeinde));
			$actkampkort_low	= preg_replace('/[^ \w-]/','',strtolower(trim($actkampkort)));// keep only letters and numbers and dashes
			$actkampkort_cap 	= preg_replace('/[^ \w-]/','',strtoupper(trim($actkampkort)));// keep only letters and numbers and dashes

			if (empty($prioriteit)) {
				$prioriteit 	= 'normaal';
			}
			if (empty($prioriteit_id)) {
				$prioriteit_id 	= 2;
			}

			$today_datetime = date("Y-m-d H:i:s");

			$activityparams_array = array(
				'displayname'			=> $displayname,
				'deltacategory'			=> $deltacategory,
		    	'prioriteit'          	=> $prioriteit,
		    	'prioriteit_id'        	=> $prioriteit_id,
		        'deltadetailsold'     	=> $deltadetailsold,
		        'deltadetailsnew'     	=> $deltadetailsnew,

		        'kampkort'     			=> $actkampkort,
		        'kampkort_low' 			=> $actkampkort_low,
		        'kampkort_cap' 			=> $actkampkort_cap,		        
		        'kamprol'     			=> $actkamprol,
		        'kampfunctie'  			=> $actkampfunctie,
		        'kampjaar'     			=> $actkampjaar,
		        'eventstart'     		=> $acteventstart,
		        'eventeinde'     		=> $acteventeinde,
			);

			if ($contact_id AND $activityparams_array) {
				delta_activity_create($contact_id, $activityparams_array);
			}
  		}

	 	wachthond($extdebug,1, "##########################################################");
	 	wachthond($extdebug,1, "### DELTA - NOTIFICATIONS OF IMPORTANT CHANGES [EINDE] ###");
	 	wachthond($extdebug,1, "###########################################################");

  	}
}
*/

/**
 * HOOK: civicrm_customPre
 * Functionele uitleg: Slaat de oude database-waarden op in het geheugen VOORDAT de form-save plaatsvindt.
 * Technische uitleg: Bepaalt dynamisch via APIv4 of het single of multi-record is en leest de oude data.
 */
function delta_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {
    $extdebug = 3;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [PRE] 0.1 BEWAREN OUDE CUSTOM DATA", "[CUSTOM_PRE]");
    wachthond($extdebug,2, "########################################################################");

    $entityTable = $params[0]['entity_table'] ?? '';
    if ($entityTable !== 'civicrm_contact' || $op !== 'edit') {
        wachthond($extdebug, 4, "SKIP: Geen Contact of geen edit actie.");
        return;
    }

    $relevante_groepen = [322, 148, 69, 149, 199];
    if (!in_array($groupID, $relevante_groepen)) return;

    // --- 1. APIv4 FIX: Haal de naam en type van de custom groep op ---
    $api_params_group = [
        'checkPermissions' => FALSE,
        'select'           => ['name', 'is_multiple', 'extends'],
        'where'            => [['id', '=', $groupID]],
    ];
    
    $group_info = civicrm_api4('CustomGroup', 'get', $api_params_group);
    $group      = $group_info[0] ?? NULL;
    
    if (!$group) return;

    $groupName  = $group['name'];
    $isMultiple = $group['is_multiple'];
    $extends    = $group['extends'];

    // --- 2. Oude data ophalen afhankelijk van architectuur ---
    $oude_waarden = [];

    if ($isMultiple) {
        $api_params_oud = [
            'checkPermissions' => FALSE,
            'where'            => [['entity_id', '=', $entityID]],
        ];
        
        $recordId = $params[0]['id'] ?? NULL;
        if ($recordId) {
            $api_params_oud['where'][] = ['id', '=', $recordId];
        }

        wachthond($extdebug, 3, "API PARAMS OPHALEN OUD (Multi-record: $groupName)", $api_params_oud);
        $oude_waarden_result = civicrm_api4('Custom_' . $groupName, 'get', $api_params_oud);
        $oude_waarden        = $oude_waarden_result[0] ?? [];
    } else {
        $api_entity = in_array($extends, ['Individual', 'Organization', 'Household']) ? 'Contact' : $extends;
        $api_params_oud = [
            'checkPermissions' => FALSE,
            'select'           => [$groupName . '.*'],
            'where'            => [['id', '=', $entityID]],
        ];

        wachthond($extdebug, 3, "API PARAMS OPHALEN OUD (Single-record via $api_entity)", $api_params_oud);
        $oude_waarden_result = civicrm_api4($api_entity, 'get', $api_params_oud);
        
        if (!empty($oude_waarden_result[0])) {
            foreach ($oude_waarden_result[0] as $key => $val) {
                if (strpos($key, $groupName . '.') === 0) {
                    $veld_naam = substr($key, strlen($groupName . '.'));
                    $oude_waarden[$veld_naam] = $val;
                }
            }
        }
    }

    // --- 3. Opslaan in werkgeheugen ---
    if (!empty($oude_waarden)) {
        Civi::$statics['delta_ext']['oud'][$entityID][$groupID] = $oude_waarden;
        wachthond($extdebug, 2, "SUCCES: Oude data opgeslagen voor $groupName.");
    }
}

/**
 * HOOK: civicrm_custom
 * Functionele uitleg: Vergelijkt de vers opgeslagen waarden met de oude waarden uit de Pre-hook.
 * Technische uitleg: Pakt de statics op, leest de nieuwe APIv4 status, schoont multi-selects op en maakt de activiteit.
 */
function delta_civicrm_custom(string $op, int $groupID, int $entityID, array &$params): void {
    $extdebug = 3;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 1.0 START WIJZIGING CUSTOM FIELD",         "[START]");
    wachthond($extdebug,2, "########################################################################");

    // Haal de oude waarden op uit het werkgeheugen (geplaatst door customPre)
    $oude_waarden = Civi::$statics['delta_ext']['oud'][$entityID][$groupID] ?? NULL;
    
    // Als er geen oude data is (bijv. bij een nieuw contact of een veld dat we negeren), stoppen we direct
    if (empty($oude_waarden)) {
        wachthond($extdebug, 4, "SKIP: Geen oude waarden gevonden in statics.");
        return; 
    }
    
    wachthond($extdebug, 4, "GEVONDEN OUDE WAARDEN IN STATICS", $oude_waarden);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 2.0 APIv4 FIX: HAAL CUSTOM GROUP INFO OP", "[GROUP_INFO]");
    wachthond($extdebug,2, "########################################################################");

    // API Parameters voor het ophalen van de groepsinformatie
    $api_params_group = [
        'checkPermissions' => FALSE,
        'select'           => ['name', 'is_multiple', 'extends'],
        'where'            => [['id', '=', $groupID]],
    ];
    
    wachthond($extdebug, 3, "API PARAMS OPHALEN CUSTOM GROUP INFO", $api_params_group);
    $group_info = civicrm_api4('CustomGroup', 'get', $api_params_group);
    $group      = $group_info[0] ?? NULL;
    
    // Stop als de custom groep niet (meer) bestaat
    if (!$group) {
        wachthond($extdebug, 4, "ERROR: Custom group niet gevonden, we stoppen.");
        return;
    }

    // Variabelen toewijzen voor verder gebruik in de code
    $groupName  = $group['name'];
    $isMultiple = $group['is_multiple'];
    $extends    = $group['extends'];

    wachthond($extdebug, 4, "CUSTOM GROUP EIGENSCHAPPEN BEPAALD", [
        'groupName'  => $groupName, 
        'isMultiple' => $isMultiple, 
        'extends'    => $extends
    ]);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 3.0 HAAL NIEUWE WAARDEN OP UIT DATABASE",  "[NEW_VALUES]");
    wachthond($extdebug,2, "########################################################################");

    $nieuwe_waarden = [];

    // Ophalen van waarden verschilt technisch voor Single-record vs Multi-record groepen
    if ($isMultiple) {
        // Multi-record: De custom groep heeft een eigen API-entiteit (bijv. Custom_Medisch)
        $api_params_nieuw = [
            'checkPermissions' => FALSE,
            'where'            => [['entity_id', '=', $entityID]],
        ];
        
        $recordId = $params[0]['id'] ?? NULL;
        if ($recordId) {
            $api_params_nieuw['where'][] = ['id', '=', $recordId];
            wachthond($extdebug, 4, "SPECIFIEK RECORD ID GEVONDEN VOOR MULTI-RECORD", $recordId);
        }
        
        wachthond($extdebug, 3, "API PARAMS OPHALEN NIEUW (Multi-record)", $api_params_nieuw);
        $nieuwe_waarden_result = civicrm_api4('Custom_' . $groupName, 'get', $api_params_nieuw);
        $nieuwe_waarden        = $nieuwe_waarden_result[0] ?? [];
        
    } else {
        // Single-record: We moeten de velden opvragen via de hoofd-entiteit (bijv. Contact)
        $api_entity       = in_array($extends, ['Individual', 'Organization', 'Household']) ? 'Contact' : $extends;
        $api_params_nieuw = [
            'checkPermissions' => FALSE,
            'select'           => [$groupName . '.*'],
            'where'            => [['id', '=', $entityID]],
        ];
        
        wachthond($extdebug, 3, "API PARAMS OPHALEN NIEUW (Single-record)", $api_params_nieuw);
        $nieuwe_waarden_result = civicrm_api4($api_entity, 'get', $api_params_nieuw);
        
        // Strip de groepsnaam prefix (bijv. "Medisch.") weg zodat alleen de veldnaam overblijft
        if (!empty($nieuwe_waarden_result[0])) {
            foreach ($nieuwe_waarden_result[0] as $key => $val) {
                if (strpos($key, $groupName . '.') === 0) {
                    $veld_naam                  = substr($key, strlen($groupName . '.'));
                    $nieuwe_waarden[$veld_naam] = $val;
                }
            }
        }
    }

    wachthond($extdebug, 4, "RESULTAAT NIEUWE WAARDEN UIT DB", $nieuwe_waarden);

    // Als er na ophalen geen waarden zijn, ruim dan op en stop
    if (empty($nieuwe_waarden)) {
        wachthond($extdebug, 3, "GEEN NIEUWE WAARDEN GEVONDEN, WERKGEHEUGEN OPRUIMEN EN STOPPEN.");
        unset(Civi::$statics['delta_ext']['oud'][$entityID][$groupID]);
        return;
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 4.0 HAAL VELDLABELS OP VOOR VERGELIJKING", "[LABELS]");
    wachthond($extdebug,2, "########################################################################");

    // Haal de gebruiksvriendelijke namen (labels) van de velden op voor in de e-mail
    $api_params_velden = [
        'checkPermissions' => FALSE,
        'where'            => [['custom_group_id', '=', $groupID]],
    ];
    
    wachthond($extdebug, 3, "API PARAMS OPHALEN VELDLABELS", $api_params_velden);
    $velden_meta_result = civicrm_api4('CustomField', 'get', $api_params_velden);
    
    $veld_info = [];
    foreach ($velden_meta_result as $fm) {
        $veld_info[$fm['name']] = $fm;
    }

    wachthond($extdebug, 5, "GEMAPTE VELDLABELS", $veld_info);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 5.0 VERGELIJK OUDE EN NIEUWE WAARDEN",     "[COMPARE]");
    wachthond($extdebug,2, "########################################################################");

    // Basisconfiguratie voor de mogelijke activiteit
    $create_activity = 0;
    $changes         = ['old' => [], 'new' => []];
    $prioriteit      = 'Normaal';
    $prioriteit_id   = 2;
    $deltacategory   = 'custom';

    // Bepaal de categorie voor logging op basis van het groep ID
    if ($groupID == 322) $deltacategory = 'gedrag';
    if ($groupID == 148) $deltacategory = 'medisch';
    if ($groupID == 69)  $deltacategory = 'bio';
    if ($groupID == 149) $deltacategory = 'talent';
    if ($groupID == 199) $deltacategory = 'ditjaar';

    // Bepaal welke specifieke velden de prioriteit 'Urgent' moeten triggeren
    $urgent_velden = [
        'medisch_medicatie', 'medisch_toelichting', 'medisch_toelichting ', 
        'gedrag_shortlist', 'gedrag_toelichting', 'dieet_shortlist', 
        'dieet_toelichting', 'ditjaar_groep_klas', 'ditjaar_voorkeur', 
        'ditjaar_fietshuur', 'Ben je christen?'
    ];

    wachthond($extdebug, 4, "START LOOP OVER ALLE VELDEN");

    // Loop door alle nieuw opgehaalde velden en vergelijk deze met de oude waarden
    foreach ($nieuwe_waarden as $veld_naam => $nieuwe_waarde) {
        
        // Sla systeemvelden over
        if ($veld_naam === 'id' || $veld_naam === 'entity_id') continue;
        if (strpos($veld_naam, 'modified') !== false) continue;

        $oude_waarde = $oude_waarden[$veld_naam] ?? NULL;
        $label       = $veld_info[$veld_naam]['label'] ?? $veld_naam;

        wachthond($extdebug, 5, "VERWERKEN VELD: $veld_naam", [
            'label'  => $label, 
            'oud_ruw'  => $oude_waarde, 
            'nieuw_ruw'=> $nieuwe_waarde
        ]);

        // -----------------------------------------------------------------------------------------
        // BULLETPROOF WASSTRAAT VOOR MULTI-SELECTS (Checkboxes e.d.)
        // CiviCRM bewaart deze vaak met onzichtbare tekens zoals \x01 of \x02. 
        // We verpulveren de string naar een array, filteren lege rommel weg, en plakken het 
        // kraakhelder weer aan elkaar, zodat rand-komma's definitief tot het verleden behoren.
        // -----------------------------------------------------------------------------------------

		// 5.1 Normaliseer BEIDE strings EERST via jouw smart-formatter
        $oud_format   = format_civicrm_smart($oude_waarde, $veld_naam);
        $nieuw_format = format_civicrm_smart($nieuwe_waarde, $veld_naam);

		// -----------------------------------------------------------------------------------------
        // 5.2 BULLETPROOF WASSTRAAT VOOR MULTI-SELECTS (Nu ná de formatter!)
        // Omdat de database of de format_civicrm_smart functie zelf soms onzichtbare 
        // tekens (\x01) of defecte tekens toevoegt, filteren we de uiteindelijke 
        // output pas op het allerlaatst. We gebruiken de Unicode \u{FFFD} en UTF-8 
        // byte sequence \xEF\xBF\xBD om die ruitjes-vraagtekens onbreekbaar te detecteren.
        // -----------------------------------------------------------------------------------------

        // OUD opschonen
        if (is_array($oud_format)) {
            $oud_format = implode(',', $oud_format);
        }
        if (is_string($oud_format)) {
            $oud_format = str_replace(["\x01", "\x02", ";", "|", "\xEF\xBF\xBD", "\u{FFFD}"], ",", $oud_format);
            $oud_format = implode(', ', array_filter(array_map('trim', explode(',', $oud_format)), 'strlen'));
        }

        // NIEUW opschonen
        if (is_array($nieuw_format)) {
            $nieuw_format = implode(',', $nieuw_format);
        }
        if (is_string($nieuw_format)) {
            $nieuw_format = str_replace(["\x01", "\x02", ";", "|", "\xEF\xBF\xBD", "\u{FFFD}"], ",", $nieuw_format);
            $nieuw_format = implode(', ', array_filter(array_map('trim', explode(',', $nieuw_format)), 'strlen'));
        }

        // De échte vergelijking: Is er functioneel iets gewijzigd?
        if ($oud_format !== $nieuw_format) {
            $create_activity = 1;
            $tab             = delta_calctabs($label);

            // Verhoog de prioriteit als een urgent veld is geraakt
            if (in_array($label, $urgent_velden) || in_array($veld_naam, $urgent_velden)) {
                $prioriteit    = 'Urgent';
                $prioriteit_id = 1;
                wachthond($extdebug, 4, "URGENT VELD GERAAKT", $label);
            }

            // Specifieke categorie-overrides op basis van het veld
            if (strpos($veld_naam, 'ditjaar_voorkeur') !== false)  $deltacategory = 'voorkeur';
            if (strpos($veld_naam, 'ditjaar_fietshuur') !== false) $deltacategory = 'fietshuur';

            // Registreer de wijzigingen netjes voor weergave in het pre-blok (\n)
        	$changes['old'][] = $label . $tab . ": " . ($oud_format  	?: '[was leeg]');
        	$changes['new'][] = $label . $tab . ": " . ($nieuw_format 	?: '[leeggemaakt]');
            
            wachthond($extdebug, 2, "MATCH: Wijziging gedetecteerd in DB", ['veld' => $label, 'oud' => $oud_format, 'nieuw' => $nieuw_format]);
        }
    }

    wachthond($extdebug, 4, "RESULTAAT VERGELIJKING LOOP", [
        'create_activity' => $create_activity, 
        'changes'         => $changes
    ]);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 6.0 MAAK ACTIVITEIT AAN INDIEN GEWIJZIGD", "[ACTIVITY]");
    wachthond($extdebug,2, "########################################################################");

    // Maak de activiteit aan als tenminste één veld echt is gewijzigd
    if ($create_activity == 1) {
        
        // Haal aanvullende contact- en kampgegevens op voor de activiteit parameters
        $api_params_contact = [
            'checkPermissions' => FALSE,
            'select'           => [
                'display_name', 'DITJAAR.DITJAAR_cid', 'DITJAAR.DITJAAR_pid', 
                'DITJAAR.DITJAAR_rol', 'DITJAAR.DITJAAR_functie', 'DITJAAR.DITJAAR_kampkort', 
                'DITJAAR.DITJAAR_kampjaar', 'DITJAAR.DITJAAR_event_start', 'DITJAAR.DITJAAR_event_end'
            ],
            'where'            => [['id', '=', $entityID]],
        ];
        
        wachthond($extdebug, 3, "API PARAMS OPHALEN CONTACT DETAILS", $api_params_contact);
        $result_contact_array = civicrm_api4('Contact', 'get', $api_params_contact);
        $result_contact       = $result_contact_array[0] ?? [];
        
        wachthond($extdebug, 4, "RESULTAAT CONTACT DETAILS", $result_contact);

        $actkampkort          = $result_contact['DITJAAR.DITJAAR_kampkort'] ?? '';

        // Parameters samenstellen voor delta_activity_create
        $activityparams_array = [
            'displayname'       => $result_contact['display_name'] ?? '',
            'deltacategory'     => $deltacategory,
            'prioriteit'        => $prioriteit,
            'prioriteit_id'     => $prioriteit_id,
            'deltadetailsold'   => implode("\n", $changes['old']),
            'deltadetailsnew'   => implode("\n", $changes['new']),

            'kampkort'          => $actkampkort,
            'kampkort_low'      => preg_replace('/[^ \w-]/', '', strtolower(trim($actkampkort))),
            'kampkort_cap'      => preg_replace('/[^ \w-]/', '', strtoupper(trim($actkampkort))),
            'kamprol'           => $result_contact['DITJAAR.DITJAAR_rol'] ?? '',
            'kampfunctie'       => $result_contact['DITJAAR.DITJAAR_functie'] ?? '',
            'kampjaar'          => $result_contact['DITJAAR.DITJAAR_kampjaar'] ?? '',
            'eventstart'        => !empty($result_contact['DITJAAR.DITJAAR_event_start']) ? date('Y-m-d H:i:s', strtotime($result_contact['DITJAAR.DITJAAR_event_start'])) : '',
            'eventeinde'        => !empty($result_contact['DITJAAR.DITJAAR_event_end'])   ? date('Y-m-d H:i:s', strtotime($result_contact['DITJAAR.DITJAAR_event_end']))   : '',
        ];

        wachthond($extdebug, 3, "PARAMS VOOR DELTA ACTIVITY CREATE", $activityparams_array);
        delta_activity_create($entityID, $activityparams_array);
        wachthond($extdebug, 1, "### SUCCES - CUSTOM ACTIVITEIT GEMAAKT", "[SUCCESS]");
    } else {
        wachthond($extdebug, 3, "GEEN WIJZIGINGEN GEDETECTEERD, GEEN ACTIVITEIT GEMAAKT.");
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 7.0 OPRUIMEN WERKGEHEUGEN EN EINDE",       "[EINDE]");
    wachthond($extdebug,2, "########################################################################");

    // Ruim het werkgeheugen op om fouten bij bulk-updates te voorkomen
    unset(Civi::$statics['delta_ext']['oud'][$entityID][$groupID]);
    wachthond($extdebug, 4, "STATICS OPGESCHOOND VOOR ENTITY EN GROUP", ['entityID' => $entityID, 'groupID' => $groupID]);
}

function delta_activity_create(int $contactid, array $activityparams) {

	if (empty($contactid) OR empty($activityparams)) {
		return;
	}

 	$extdebug			= 0; // 1 = basic // 2 = verbose // 3 = params / 4 = results

 	wachthond($extdebug,2, "###################################################$#######");
 	wachthond($extdebug,2, "### DELTA - CREATE ACTIVITY DELTA", 			   "[START]");
 	wachthond($extdebug,2, "###########################################################");

	wachthond($extdebug,2, 'activityparams',	$activityparams);

	$contact_id 		= $contactid;
	$today_datetime 	= date("Y-m-d H:i:s");

	$displayname		= $activityparams['displayname'] 		?? NULL;
	$deltacategory		= $activityparams['deltacategory'] 		?? NULL;
	$prioriteit			= $activityparams['prioriteit'] 		?? NULL;
	$prioriteit_id		= $activityparams['prioriteit_id'] 		?? NULL;	
	$deltadetailsold	= $activityparams['deltadetailsold'] 	?? NULL;
	$deltadetailsnew	= $activityparams['deltadetailsnew'] 	?? NULL;

	$actkampkort		= $activityparams['kampkort'] 			?? NULL;
	$actkampkort_low 	= $activityparams['kampkort_low'] 		?? NULL;
	$actkampkort_cap 	= $activityparams['kampkort_cap'] 		?? NULL;	

	$actkamprol			= $activityparams['kamprol'] 			?? NULL;
	$actkampfunctie		= $activityparams['kampfunctie'] 		?? NULL;
	$actkampjaar		= $activityparams['kampjaar'] 			?? NULL;
	$acteventstart		= $activityparams['eventstart'] 		?? NULL;
	$acteventeinde		= $activityparams['eventeinde'] 		?? NULL;

	wachthond($extdebug,2, 'displayname',		$displayname);

	wachthond($extdebug,2, 'deltacategory',		$deltacategory);
	wachthond($extdebug,2, 'prioriteit', 		$prioriteit);
	wachthond($extdebug,2, 'prioriteit_id', 	$prioriteit_id);
	wachthond($extdebug,2, 'deltadetailsold', 	$deltadetailsold);
	wachthond($extdebug,2, 'deltadetailsnew', 	$deltadetailsnew);

	wachthond($extdebug,2, 'kampkort', 			$kampkort);	
	wachthond($extdebug,2, 'kampkort_low', 		$kampkort_low);	
	wachthond($extdebug,2, 'kampkort_cap', 		$kampkort_cap);

	wachthond($extdebug,2, 'kamprol', 			$actkamprol);
	wachthond($extdebug,2, 'kampfunctie',		$actkampfunctie);
	wachthond($extdebug,2, 'kampjaar',			$actkampjaar);
	wachthond($extdebug,2, 'eventstart',		$acteventstart);
	wachthond($extdebug,2, 'eventeinde',		$acteventeinde);

	if (empty($prioriteit)) {
		$prioriteit = 'normaal';
	}	

	if ($contact_id) {

		$params_activity_delta_create = [
			'checkPermissions' => FALSE,
			'values' => [
    			'source_contact_id' 		=> 1,
				'target_contact_id' 		=> $contact_id,
				'activity_type_id:name' 	=> 'Notificatie aandachtspunten',
				'subject' 					=> 'Aanpassing: '. $deltacategory,
				'details' 					=> '<h3><b>OUD :</b></h3>'.$deltadetailsold.'<h3><b>NEW :</b></h3>'.$deltadetailsnew,
				'status_id:name' 			=> 'Completed',
				'priority_id' 				=> $prioriteit_id,
				'AANPASSINGEN.Categorie'	=> $deltacategory,
				'AANPASSINGEN.OUD' 			=> '<pre>'.$deltadetailsold.'</pre>',
				'AANPASSINGEN.NEW' 			=> '<pre>'.$deltadetailsnew.'</pre>',

				'ACT_ALG.actcontact_cid' 	=> $contact_id,
				'ACT_ALG.actcontact_naam' 	=> $actdisplayname,
				'ACT_ALG.prioriteit:label' 	=> $prioriteit,
				'ACT_ALG.modified' 			=> $today_datetime,
				'ACT_ALG.afgerond' 			=> $today_datetime,
			],
		];

		if ($actdisplayname)	{
			$params_activity_delta_create['values']['ACT_ALG.actcontact_naam']	= $actdisplayname;
		}
		if ($actcontact_cid)	{
			$params_activity_delta_create['values']['ACT_ALG.actcontact_cid'] 	= $actcontact_cid;
		}
		if ($actkampkort_low)	{
			$params_activity_delta_create['values']['ACT_ALG.kampnaam']			= $actkampkort_cap;
		}
		if ($actkampkort_cap)	{
			$params_activity_delta_create['values']['ACT_ALG.kampkort']			= $actkampkort_low;
		}
		if ($acteventstart)		{
			$params_activity_delta_create['values']['ACT_ALG.kampstart']		= $acteventstart;
		}
		if ($acteventeinde) 	{
			$params_activity_delta_create['values']['ACT_ALG.kampeinde']		= $acteventeinde;
		}
		if ($actkampfunctie) 	{
			$params_activity_delta_create['values']['ACT_ALG.kampfunctie']		= $actkampfunctie;
		}
		if ($actkampjaar) 		{
			$params_activity_delta_create['values']['ACT_ALG.kampjaar']			= $actkampjaar;
		}

		wachthond($extdebug,3, 'params_activity_delta_create', 				$params_activity_delta_create);
		$result_activity_delta_create = civicrm_api4('Activity', 'create', 	$params_activity_delta_create);
		wachthond($extdebug,4, 'result_activity_delta_create', 				$result_activity_delta_create);
	}

		wachthond($extdebug,2, "###################################################$#######");
		wachthond($extdebug,2, "### DELTA - CREATE ACTIVITY DELTA", 			 "[CREATED]");
		wachthond($extdebug,2, "###########################################################");
}


/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function delta_civicrm_config(&$config): void {
  _delta_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function delta_civicrm_install(): void {
  _delta_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function delta_civicrm_enable(): void {
  _delta_civix_civicrm_enable();
}
