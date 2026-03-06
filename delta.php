<?php

/**
 * =========================================================================================
 * EXTENSIE: DELTA (Notificatie aandachtspunten)
 * =========================================================================================
 * Functionele uitleg: 
 * Deze extensie controleert of gebruikers belangrijke velden (zoals medisch, gedrag, bio)
 * wijzigen bij een contact. Als er een échte, functionele wijziging is, maakt het systeem
 * automatisch een activiteit (Notificatie aandachtspunten) aan zodat het kantoor actie kan 
 * ondernemen.
 *
 * Technische uitleg:
 * De extensie maakt gebruik van CiviCRM Hooks (pre, customPre, custom). Omdat CiviCRM bij 
 * een 'post' actie de oude data al is vergeten, gebruiken we Civi::$statics (werkgeheugen) 
 * om de brug te slaan tussen het 'Pre' (oude data ophalen) en 'Post' (nieuwe data ophalen
 * en vergelijken) moment.
 * =========================================================================================
 */

require_once 'delta.civix.php';

use CRM_Delta_ExtensionUtil as E;

/**
 * HELPER: multi_implode
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
 */
function delta_calctabs($message) {
    $messagelenght = strlen($message);
    $target_length = 20; 
    
    $spaces = $target_length - $messagelenght;
    
    if ($spaces < 2) {
        $spaces = 2; 
    }
    
    return str_repeat("&nbsp;", $spaces);
}

/**
 * HOOK: civicrm_pre
 */
function delta_civicrm_pre($op, $objectName, $id, &$params) {

	// Statische vlaggen om het gehele PHP-proces (request) te beveiligen
    static $request_skip  = FALSE;
    static $processed_ids = [];

    $extdebug = 3;

    // --- BEVEILIGING 1: Check of dit request al gemarkeerd is als 'nieuw contact' ---
    if ($request_skip) {
    	wachthond($extdebug, 3, 'params', 	$params);
        wachthond($extdebug, 3, "DELTA [PRE] SKIP: Request staat op skip (eerder nieuw contact gedetecteerd).");
        return;
    }
    
    // --- BEVEILIGING 2: Voorkom dubbele runs voor hetzelfde ID binnen één request ---
    if (!empty($id) && isset($processed_ids[$id])) {
    	wachthond($extdebug, 3, 'params', 	$params);
        wachthond($extdebug, 3, "DELTA [PRE] SKIP: ID $id is al verwerkt in dit request.");
        return;
    }

    // --- BEVEILIGING 3: De 'Create' filter (Nieuw contact) ---
    if (empty($id) || $op === 'create') {
    	wachthond($extdebug, 3, 'params', 	$params);
        wachthond($extdebug, 3, "DELTA [PRE] SKIP: Nieuw contact gedetecteerd (op: $op, id: leeg). Request op blokkade.");
        $request_skip = TRUE; 
        return;
    }

    // Alleen verwerken als het een persoon (Individual/Contact) is
    if (!in_array($objectName, ['Individual', 'Contact'])) {
    	wachthond($extdebug, 4, 'params', 	$params);
        wachthond($extdebug, 4, "DELTA [PRE] SKIP: Object $objectName is geen Contact.");
        return;
    }

    // Registreer ID voor legitieme wijzigingen
    $processed_ids[$id] = TRUE;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [PRE] 1.0 CONTROLEER CORE CONTACT VELDEN $id",        "[START]");
    wachthond($extdebug,2, "########################################################################");

    wachthond($extdebug, 3, 'params', 	$params);
    wachthond($extdebug, 3, 'op', 		$op);
    wachthond($extdebug, 3, 'id', 		$id);

    $contact_id      = $id;
    $create_activity = 0;
    $changes         = ['old' => [], 'new' => []];
    $deltacategory   = 'demografic';

    $params_contact_get = [
        'checkPermissions' => FALSE,
        'select'           => [
            'display_name', 'first_name', 'middle_name', 'last_name', 'nickname',
            'birth_date', 'gender_id', 'gender_id:label', 'DITJAAR.DITJAAR_kampkort'
        ],
        'where'            => [['id', '=', $contact_id]],
    ];

    wachthond($extdebug, 7, 'params_contact_get',           $params_contact_get);
    $result_contact_get = civicrm_api4('Contact', 'get',    $params_contact_get);
    wachthond($extdebug, 9, 'result_contact_get',           $result_contact_get);

    $result_contact = $result_contact_get[0] ?? [];

    if (empty($result_contact)) {
        wachthond($extdebug, 4, "DELTA [PRE] SKIP: Contact $contact_id niet gevonden in DB.");
        return;
    }

    $fields = [
        'first_name'  => 'Voornaam', 
        'middle_name' => 'Tussenvoegsel',
        'last_name'   => 'Achternaam', 
        'nickname'    => 'Meisjesnaam',
        'gender_id'   => 'Geslacht',
        'birth_date'  => 'Geboortedatum'
    ];

    foreach ($fields as $key => $label) {
        if (isset($params[$key])) {
            $old_v = format_civicrm_smart($result_contact[$key] ?? '', $key);
            $new_v = format_civicrm_smart($params[$key], $key);

            if ($key === 'birth_date') {
                $old_v = !empty($old_v) ? date('Y-m-d', strtotime($old_v)) : '';
                $new_v = !empty($new_v) ? date('Y-m-d', strtotime($new_v)) : '';
            }

            if ($old_v !== $new_v) {
                $create_activity = 1;
                $tab             = delta_calctabs($label);
                
                $old_display = ($key === 'gender_id') ? ($result_contact['gender_id:label'] ?? $old_v) : $old_v;
                $new_display = ($key === 'gender_id') ? ($params[$key] == 1 ? 'meisje' : 'jongen') : $new_v;

                $changes['old'][] = $label . $tab . ": " . ($old_display ?: '(was leeg)');
                $changes['new'][] = $label . $tab . ": " . ($new_display ?: '(leeggemaakt)');
            }
        }
    }

    if ($create_activity == 1) {
        $deltadetailsold = implode("<br>", $changes['old']);
        $deltadetailsnew = implode("<br>", $changes['new']);

        $activityparams_array = [
            'displayname'     => $result_contact['display_name'] ?? NULL,
            'deltacategory'   => $deltacategory,
            'prioriteit'      => 'Urgent',
            'prioriteit_id'   => 1,
            'deltadetailsold' => $deltadetailsold,
            'deltadetailsnew' => $deltadetailsnew,
            'kampkort'        => $result_contact['DITJAAR.DITJAAR_kampkort'] ?? NULL,
        ];
        
        wachthond($extdebug, 3, "PARAMS VOOR DELTA CORE ACTIVITY CREATE", $activityparams_array);
        delta_activity_create($contact_id, $activityparams_array);
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [PRE] 1.0 CONTROLEER CORE CONTACT VELDEN $id",        "[EINDE]");
    wachthond($extdebug,2, "########################################################################");

}

/**
 * HOOK: civicrm_customPre
 */
function delta_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

	static $already_prepped = [];
    if (isset($already_prepped[$entityID][$groupID])) return;
    
    $extdebug = 3;

	// --- FIX: Strenge controle op operatie en aanwezigheid ID ---
    if ($op !== 'edit' || empty($entityID)) {
        wachthond($extdebug, 4, "DELTA [CUSTOM-PRE] SKIP: Geen edit actie (op: $op) of ID leeg ($entityID)");
        return;
    }

    // Controleer of het contact daadwerkelijk al bestaat in de database
    $contactExists = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $entityID, 'id');
    if (!$contactExists) {
        wachthond($extdebug, 4, "DELTA [CUSTOM-PRE] SKIP: Contact $entityID bestaat nog niet in DB (transactie isolatie).");
        return; 
    }

    $entityTable = $params[0]['entity_table'] ?? '';
	if ($entityTable !== 'civicrm_contact') {
        return;
	}

    $relevante_groepen = [322, 148, 69, 149, 199];
    if (!in_array($groupID, $relevante_groepen)) {
        return;
    }
    
    $already_prepped[$entityID][$groupID] = TRUE;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [PRE] 0.1 BEWAREN OUDE CUSTOM DATA",              "[CUSTOM_PRE]");
    wachthond($extdebug,2, "########################################################################");

    $params_customgroup_get = [
        'checkPermissions' => FALSE,
        'select'           => ['name', 'is_multiple', 'extends'],
        'where'            => [['id', '=', $groupID]],
    ];
    
    $result_customgroup_get = civicrm_api4('CustomGroup','get', $params_customgroup_get);
    $group = $result_customgroup_get[0] ?? NULL;
    
    if (!$group) return;

    $groupName  = $group['name'];
    $isMultiple = $group['is_multiple'];
    $extends    = $group['extends'];

    $oude_waarden = [];

    if ($isMultiple) {
        $params_custom_get = [
            'checkPermissions' => FALSE,
            'where'            => [['entity_id', '=', $entityID]],
        ];
        $recordId = $params[0]['id'] ?? NULL;
        if ($recordId) {
            $params_custom_get['where'][] = ['id', '=', $recordId];
        }
        $result_custom_get = civicrm_api4('Custom_' . $groupName,'get', $params_custom_get);
        $oude_waarden = $result_custom_get[0] ?? [];
    } else {
        $api_entity = in_array($extends, ['Individual', 'Organization', 'Household']) ? 'Contact' : $extends;
        $params_entity_get = [
            'checkPermissions' => FALSE,
            'select'           => [$groupName . '.*'],
            'where'            => [['id', '=', $entityID]],
        ];
        $result_entity_get = civicrm_api4($api_entity, 'get', $params_entity_get);
        if (!empty($result_entity_get[0])) {
            foreach ($result_entity_get[0] as $key => $val) {
                if (strpos($key, $groupName . '.') === 0) {
                    $veld_naam                = substr($key, strlen($groupName . '.'));
                    $oude_waarden[$veld_naam] = $val;
                }
            }
        }
    }

    if (!empty($oude_waarden)) {
        Civi::$statics['delta_ext']['oud'][$entityID][$groupID] = $oude_waarden;
        wachthond($extdebug, 2, "SUCCES: Oude data opgeslagen voor $groupName.");
    }
}

/**
 * HOOK: civicrm_custom
 */
function delta_civicrm_custom(string $op, int $groupID, int $entityID, array &$params): void {

	static $already_done = [];
    if (isset($already_done[$entityID][$groupID])) return;

    $extdebug = 3;

    if ($op !== 'edit' || empty($entityID)) {
        wachthond($extdebug, 4, "DELTA [CUSTOM-POST] SKIP: Geen edit actie (op: $op) of ID leeg ($entityID)");
        return;
    }
    
    $contactExists = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $entityID, 'id');
    if (!$contactExists) {
        wachthond($extdebug, 4, "DELTA [CUSTOM-POST] SKIP: Contact $entityID bestaat nog niet in DB.");
        return; 
    }
    
    $already_done[$entityID][$groupID] = TRUE;

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA [POST] 1.0 START WIJZIGING CUSTOM FIELD",         "[START]");
    wachthond($extdebug,2, "########################################################################");

    $oude_waarden = Civi::$statics['delta_ext']['oud'][$entityID][$groupID] ?? NULL;
    
    if (empty($oude_waarden)) {
        wachthond($extdebug, 4, "SKIP: Geen oude waarden gevonden in statics.");
        return; 
    }
    
    $params_customgroup_get = [
        'checkPermissions' => FALSE,
        'select'           => ['name', 'is_multiple', 'extends'],
        'where'            => [['id', '=', $groupID]],
    ];
    
    $result_customgroup_get = civicrm_api4('CustomGroup', 'get', $params_customgroup_get);
    $group = $result_customgroup_get[0] ?? NULL;
    
    if (!$group) return;

    $groupName  = $group['name'];
    $isMultiple = $group['is_multiple'];
    $extends    = $group['extends'];

    $nieuwe_waarden = [];

    if ($isMultiple) {
        $params_custom_get = [
            'checkPermissions' => FALSE,
            'where'            => [['entity_id', '=', $entityID]],
        ];
        $recordId = $params[0]['id'] ?? NULL;
        if ($recordId) {
            $params_custom_get['where'][] = ['id', '=', $recordId];
        }
        $result_custom_get = civicrm_api4('Custom_' . $groupName, 'get', $params_custom_get);
        $nieuwe_waarden = $result_custom_get[0] ?? [];
    } else {
        $api_entity = in_array($extends, ['Individual', 'Organization', 'Household']) ? 'Contact' : $extends;
        $params_entity_get = [
            'checkPermissions' => FALSE,
            'select'           => [$groupName . '.*'],
            'where'            => [['id', '=', $entityID]],
        ];
        $result_entity_get = civicrm_api4($api_entity, 'get', $params_entity_get);
        if (!empty($result_entity_get[0])) {
            foreach ($result_entity_get[0] as $key => $val) {
                if (strpos($key, $groupName . '.') === 0) {
                    $veld_naam                  = substr($key, strlen($groupName . '.'));
                    $nieuwe_waarden[$veld_naam] = $val;
                }
            }
        }
    }

    if (empty($nieuwe_waarden)) {
        unset(Civi::$statics['delta_ext']['oud'][$entityID][$groupID]);
        return;
    }

    $params_customfield_get = [
        'checkPermissions' => FALSE,
        'select'           => ['*', 'option_group_id'], 
        'where'            => [['custom_group_id', '=', $groupID]],
    ];
    
    $result_customfield_get = civicrm_api4('CustomField','get', $params_customfield_get);
    $veld_info        = [];
    $option_group_ids = [];
    
    foreach ($result_customfield_get as $fm) {
        $veld_info[$fm['name']] = $fm;
        if (!empty($fm['option_group_id']) && strpos($fm['name'], 'check') !== false) {
            $option_group_ids[] = $fm['option_group_id'];
        }
    }

    $option_values = [];
    if (!empty($option_group_ids)) {
        $params_option_get = [
            'checkPermissions' => FALSE,
            'where'            => [['option_group_id', 'IN', $option_group_ids]],
            'limit'            => 0, 
        ];
        $result_option_get = civicrm_api4('OptionValue', 'get', $params_option_get);
        foreach ($result_option_get as $opt) {
            $option_values[$opt['option_group_id']][$opt['value']] = $opt['label'];
        }
    }

    $log_instellingen = [
        'gedrag'  => true,
        'medisch' => true,
        'bio'     => true,
        'talent'  => false,
        'ditjaar' => false,
    ];

    $log_uitzonderingen = [
        'medisch_medicatie',    'medisch_toelichting',  'medisch_toelichting ', 
        'gedrag_shortlist',     'gedrag_toelichting',   'dieet_shortlist', 
        'dieet_toelichting',    'ditjaar_groep_klas',   'ditjaar_voorkeur', 
        'ditjaar_fietshuur',    'Ben je christen?',     
        'medisch_check',		'dieet_check',          'gedrag_check'
    ];

    $altijd_urgent = [
        'medisch_check',
        'dieet_check',
        'gedrag_check'
    ];

    $urgent_binnen_4_weken = [
        'ditjaar_voorkeur', 
        'ditjaar_fietshuur'
    ];

    $log_prio_laag_opslaan = true;

    $create_activity = 0;
    $changes         = ['old' => [], 'new' => []];
    $deltacategory   = 'custom';
    $prioriteit      = 'Laag';
    $prioriteit_id   = 3; 

    if ($groupID == 322) $deltacategory = 'gedrag';
    if ($groupID == 148) $deltacategory = 'medisch';
    if ($groupID == 69)  $deltacategory = 'bio';
    if ($groupID == 149) $deltacategory = 'talent';
    if ($groupID == 199) $deltacategory = 'ditjaar';

    $params_contact_get = [
        'checkPermissions' => FALSE,
        'select'           => [
            'display_name', 			'DITJAAR.DITJAAR_cid', 			'DITJAAR.DITJAAR_pid', 
            'DITJAAR.DITJAAR_rol', 		'DITJAAR.DITJAAR_functie', 		'DITJAAR.DITJAAR_kampkort', 
            'DITJAAR.DITJAAR_kampjaar', 'DITJAAR.DITJAAR_event_start', 	'DITJAAR.DITJAAR_event_end'
        ],
        'where'            => [['id', '=', $entityID]],
    ];
    
    $result_contact_get = civicrm_api4('Contact', 'get',    $params_contact_get);
    $result_contact = $result_contact_get[0] ?? [];
    $actkampkort    = $result_contact['DITJAAR.DITJAAR_kampkort'] ?? '';

    $is_binnen_4_weken = false;
    if (!empty($result_contact['DITJAAR.DITJAAR_event_start'])) {
        $start_datum = strtotime($result_contact['DITJAAR.DITJAAR_event_start']);
        $nu          = time();
        $dagen_tot_kamp = ($start_datum - $nu) / (60 * 60 * 24);
        if ($dagen_tot_kamp <= 28) $is_binnen_4_weken = true;
    }

    foreach ($nieuwe_waarden as $veld_naam => $nieuwe_waarde) {
        if ($veld_naam === 'id' || $veld_naam === 'entity_id') continue;
        if (strpos($veld_naam, 'modified') !== false) continue;

        $label = $veld_info[$veld_naam]['label'] ?? $veld_naam;
        $is_uitzondering = false;
        foreach ($log_uitzonderingen as $uitzondering) {
            if (strpos($veld_naam, $uitzondering) !== false || strpos($label, $uitzondering) !== false) {
                $is_uitzondering = true;
                break;
            }
        }
        
        $mag_loggen = $log_instellingen[$deltacategory] ?? true;
        if (!$mag_loggen && !$is_uitzondering) continue;

        $oude_waarde = $oude_waarden[$veld_naam] ?? NULL;
        $opt_grp_id  = $veld_info[$veld_naam]['option_group_id'] ?? NULL;

        $oud_format   = format_civicrm_smart($oude_waarde, $veld_naam);
        $nieuw_format = format_civicrm_smart($nieuwe_waarde, $veld_naam);
        
        $is_check_veld = (strpos($veld_naam, 'check') !== false);

        if (is_array($oud_format))   $oud_format = implode(',', $oud_format);
        if (is_string($oud_format)) {
            $oud_format = str_replace(["\x01", "\x02", ";", "|", "\xEF\xBF\xBD", "\u{FFFD}"], ",", $oud_format);
            $oud_arr    = array_filter(array_map('trim', explode(',', $oud_format)), 'strlen');
            if ($is_check_veld && $opt_grp_id && isset($option_values[$opt_grp_id])) {
                foreach ($oud_arr as $k => $v) if (isset($option_values[$opt_grp_id][$v])) $oud_arr[$k] = $option_values[$opt_grp_id][$v];
            }
            $oud_format = implode(', ', $oud_arr);
        }

        if (is_array($nieuw_format)) $nieuw_format = implode(',', $nieuw_format);
        if (is_string($nieuw_format)) {
            $nieuw_format = str_replace(["\x01", "\x02", ";", "|", "\xEF\xBF\xBD", "\u{FFFD}"], ",", $nieuw_format);
            $nieuw_arr    = array_filter(array_map('trim', explode(',', $nieuw_format)), 'strlen');
            if ($is_check_veld && $opt_grp_id && isset($option_values[$opt_grp_id])) {
                foreach ($nieuw_arr as $k => $v) if (isset($option_values[$opt_grp_id][$v])) $nieuw_arr[$k] = $option_values[$opt_grp_id][$v];
            }
            $nieuw_format = implode(', ', $nieuw_arr);
        }

        if ($oud_format !== $nieuw_format) {
            $create_activity = 1;
            $tab             = delta_calctabs($label);

            if ($is_uitzondering && $prioriteit_id > 2) {
                $prioriteit = 'Normaal'; $prioriteit_id = 2;
            }

            foreach ($altijd_urgent as $urgent_veld) {
                if (strpos($veld_naam, $urgent_veld) !== false || strpos($label, $urgent_veld) !== false) {
                    $prioriteit = 'Urgent'; $prioriteit_id = 1; break;
                }
            }

            if ($is_binnen_4_weken) {
                foreach ($urgent_binnen_4_weken as $notify_veld) {
                    if (strpos($veld_naam, $notify_veld) !== false || strpos($label, $notify_veld) !== false) {
                        $prioriteit = 'Urgent'; $prioriteit_id = 1; break;
                    }
                }
            }

            if (strpos($veld_naam, 'ditjaar_voorkeur')  !== false) $deltacategory = 'voorkeur';
            if (strpos($veld_naam, 'ditjaar_fietshuur') !== false) $deltacategory = 'fietshuur';

            $changes['old'][] = $label . $tab . ": " . ($oud_format   ?: '(was leeg)');
            $changes['new'][] = $label . $tab . ": " . ($nieuw_format ?: '(leeggemaakt)');
        }
    }

    if ($create_activity == 1) {
        if ($prioriteit === 'Laag' && $log_prio_laag_opslaan === false) {
            wachthond($extdebug, 3, "ACTIVITEIT GESKIPT: Prioriteit is Laag.");
        } else {
            $activityparams_array = [
                'displayname'       => $result_contact['display_name'] ?? '',
                'deltacategory'     => $deltacategory,
                'prioriteit'        => $prioriteit,
                'prioriteit_id'     => $prioriteit_id,
                'deltadetailsold'   => implode("<br>", $changes['old']),
                'deltadetailsnew'   => implode("<br>", $changes['new']),
                'kampkort'          => $actkampkort,
                'kampkort_low'      => preg_replace('/[^ \w-]/', '', strtolower(trim($actkampkort))),
                'kampkort_cap'      => preg_replace('/[^ \w-]/', '', strtoupper(trim($actkampkort))),
                'kamprol'           => $result_contact['DITJAAR.DITJAAR_rol'] ?? '',
                'kampfunctie'       => $result_contact['DITJAAR.DITJAAR_functie'] ?? '',
                'kampjaar'          => $result_contact['DITJAAR.DITJAAR_kampjaar'] ?? '',
                'eventstart'        => !empty($result_contact['DITJAAR.DITJAAR_event_start']) ? date('Y-m-d H:i:s', strtotime($result_contact['DITJAAR.DITJAAR_event_start'])) : '',
                'eventeinde'        => !empty($result_contact['DITJAAR.DITJAAR_event_end'])   ? date('Y-m-d H:i:s', strtotime($result_contact['DITJAAR.DITJAAR_event_end']))   : '',
            ];
            delta_activity_create($entityID, $activityparams_array);
        }
    }

    unset(Civi::$statics['delta_ext']['oud'][$entityID][$groupID]);
}

/**
 * HELPER: delta_activity_create
 */
function delta_activity_create(int $contactid, array $activityparams) {

	if (empty($contactid) OR empty($activityparams)) return;

 	$extdebug = 3; 

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### DELTA 8.0 CREATE ACTIVITY DELTA",                       "[START]");
    wachthond($extdebug,2, "########################################################################");

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

	if (empty($prioriteit)) $prioriteit = 'Laag';

	if ($contact_id) {

		$params_activity_delta_create = [
			'checkPermissions' => FALSE,
			'values' => [
    			'source_contact_id' 		=> 1, // Vaste bron: Kantoor/Systeem
				'target_contact_id' 		=> $contact_id,
				'activity_type_id:name' 	=> 'Notificatie aandachtspunten',
				'subject' 					=> 'Aanpassing: '. $deltacategory,
				'details' 					=> '<h3><b>OUD :</b></h3><div style="font-family: monospace; margin:0; padding:0;">'.$deltadetailsold.'</div><h3><b>NEW :</b></h3><div style="font-family: monospace; margin:0; padding:0;">'.$deltadetailsnew.'</div>',
				'status_id:name' 			=> 'Completed',
				'priority_id' 				=> $prioriteit_id,
				'AANPASSINGEN.Categorie'	=> $deltacategory,
				'AANPASSINGEN.OUD' 			=> '<div style="font-family: monospace; margin:0; padding:0;">'.$deltadetailsold.'</div>',
				'AANPASSINGEN.NEW' 			=> '<div style="font-family: monospace; margin:0; padding:0;">'.$deltadetailsnew.'</div>',
				'ACT_ALG.actcontact_cid' 	=> $contact_id,
				'ACT_ALG.actcontact_naam' 	=> $displayname,
				'ACT_ALG.prioriteit:label' 	=> $prioriteit,
				'ACT_ALG.modified' 			=> $today_datetime,
				'ACT_ALG.afgerond' 			=> $today_datetime,
			],
		];

		if ($actkampkort_low) $params_activity_delta_create['values']['ACT_ALG.kampnaam']    = $actkampkort_cap;
		if ($actkampkort_cap) $params_activity_delta_create['values']['ACT_ALG.kampkort']    = $actkampkort_low;
		if ($acteventstart)   $params_activity_delta_create['values']['ACT_ALG.kampstart']   = $acteventstart;
		if ($acteventeinde)   $params_activity_delta_create['values']['ACT_ALG.kampeinde']   = $acteventeinde;
		if ($actkampfunctie)  $params_activity_delta_create['values']['ACT_ALG.kampfunctie'] = $actkampfunctie;
		if ($actkampjaar)     $params_activity_delta_create['values']['ACT_ALG.kampjaar']    = $actkampjaar;

		$result_activity_delta_create = civicrm_api4('Activity', 'create', 	$params_activity_delta_create);
	}
}

/**
 * Implements hook_civicrm_config().
 */
function delta_civicrm_config(&$config): void {
  _delta_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function delta_civicrm_install(): void {
  _delta_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function delta_civicrm_enable(): void {
  _delta_civix_civicrm_enable();
}