<?php
/* Copyright (C) 2026
 * Licensed under the GNU General Public License version 3
 */

/**
 * \file        class/actions_upinvoice.class.php
 * \ingroup     upinvoiceimport
 * \brief       Hooks for UpInvoice: custom EmailCollector operation to import invoice attachments
 */

/**
 * Class ActionsUpinvoice
 */
class ActionsUpinvoice
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Error message
     */
    public $error = '';

    /**
     * @var string[] Errors
     */
    public $errors = array();

    /**
     * @var array Results returned to caller (merged by hook caller)
     */
    public $resArray = array();

    /**
     * @var string String printed by hook caller
     */
    public $resPrint = '';

    /**
     * Custom operation code, must start with 'hook' so EmailCollector dispatches it
     * to the doCollectImapOneCollector hook (see emailcollector.class.php)
     */
    const OPERATION_TYPE = 'hook_upinvoice_import';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Add UpInvoice operation to the list of available EmailCollector operations
     * Hook called from admin/emailcollector_card.php (context 'emailcollectorcard')
     *
     * @param array         $parameters     Hook parameters
     * @param EmailCollector $object        Current collector
     * @param string        $action         Current action
     * @param HookManager   $hookmanager    Hook manager
     * @return int                          0 on success
     */
    public function addMoreActionsEmailCollector($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        if (!getDolGlobalString('UPINVOICE_EMAILCOLLECTOR_ENABLED')) {
            return 0;
        }

        $langs->load("upinvoice@upinvoice");

        $this->resArray = array(
            self::OPERATION_TYPE => $langs->trans('UpInvoiceEmailCollectorOperation')
        );

        return 0;
    }

    /**
     * Execute the UpInvoice operation on a collected email: save PDF/image attachments
     * into the upinvoice temp directory and queue them in llx_upinvoice_files.
     * Hook called from EmailCollector::doCollectOneCollector() (context 'emailcolector'),
     * $action contains the operation type.
     *
     * @param array         $parameters     Hook parameters (connection, imapemail, attachments, subject, from, header...)
     * @param EmailCollector $object        Collector being processed
     * @param string        $action         Operation type
     * @param HookManager   $hookmanager    Hook manager
     * @return int                          <0 if error (email kept for retry), 0 if nothing done, 1 if OK
     */
    public function doCollectImapOneCollector($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;

        if ($action !== self::OPERATION_TYPE) {
            return 0;
        }
        if (!isModEnabled('upinvoice') || !getDolGlobalString('UPINVOICE_EMAILCOLLECTOR_ENABLED')) {
            return 0;
        }

        $langs->load("upinvoice@upinvoice");

        dol_include_once('/upinvoice/class/upinvoicefiles.class.php');

        // Normalize attachments to filename => content for both IMAP modes
        // (same pattern as core 'recordjoinpiece' operation in emailcollector.class.php)
        $data = array();
        if (getDolGlobalString('MAIN_IMAP_USE_PHPIMAP')) {
            if (!empty($parameters['attachments'])) {
                foreach ($parameters['attachments'] as $attachment) {
                    if ($attachment->getName() === 'undefined') {
                        continue;
                    }
                    $data[$attachment->getName()] = $attachment->getContent();
                }
            }
        } else {
            require_once DOL_DOCUMENT_ROOT.'/emailcollector/lib/emailcollector.lib.php';
            $pj = getAttachments($parameters['imapemail'], $parameters['connection']);
            foreach ($pj as $key => $val) {
                $data[$val['filename']] = getFileData($parameters['imapemail'], (string) $val['pos'], $val['type'], $parameters['connection']);
            }
        }

        if (empty($data)) {
            $this->resPrint = 'UpInvoice: no attachments in email';
            return 0; // Email is consumed, nothing to import
        }

        // Collectors are entity-scoped: queue files under the collector entity
        $entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;

        // ---------------------------------------------------------------------------
        // Load active email import rules + blacklist rules for this entity
        // ---------------------------------------------------------------------------
        $rules   = UpInvoiceFiles::loadEmailRules($this->db, $entity);
        $blRules = UpInvoiceFiles::loadBlacklistRules($this->db, $entity);

        $fromRaw    = isset($parameters['from']) ? $parameters['from'] : (isset($parameters['fromtext']) ? $parameters['fromtext'] : '');
        $subjectRaw = isset($parameters['subject']) ? $parameters['subject'] : '';

        $nbqueued = 0;
        $nbskipped = 0;

        // If the email carries a PDF, the non-PDF attachments are treated as noise
        // (logos, signatures, ...) and ignored — only the PDF is the invoice.
        $emailHasPdf = false;
        foreach ($data as $fn => $c) {
            if (strtolower(pathinfo($fn, PATHINFO_EXTENSION)) === 'pdf') {
                $emailHasPdf = true;
                break;
            }
        }

        // Each attachment is evaluated individually:
        //  - no rules defined  -> backwards-compatible default (PDF only, no rule label)
        //  - rules defined      -> attachment must match at least one rule (sender AND subject AND
        //                          filename pattern AND format); the matching rule is recorded.
        foreach ($data as $filename => $content) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Blacklist: never queue an attachment matching an active blacklist rule
            if (!empty($blRules) && UpInvoiceFiles::matchBlacklistRule($blRules, $fromRaw, $subjectRaw, $filename, $ext) !== null) {
                $nbskipped++;
                dol_syslog("ActionsUpinvoice::doCollectImapOneCollector attachment skipped by blacklist file=".$filename, LOG_INFO);
                continue;
            }

            // When the email has a PDF, ignore the non-PDF attachments
            if ($emailHasPdf && $ext !== 'pdf') {
                $nbskipped++;
                dol_syslog("ActionsUpinvoice::doCollectImapOneCollector non-PDF attachment ignored (email has PDF) file=".$filename, LOG_INFO);
                continue;
            }

            if (empty($rules)) {
                if ($ext !== 'pdf') {
                    $nbskipped++;
                    continue;
                }
                $allowedext = array('pdf' => 'application/pdf');
                $ruleLabel  = '';
            } else {
                $rule = UpInvoiceFiles::matchAttachmentRule($rules, $fromRaw, $subjectRaw, $filename, $ext);
                if ($rule === null) {
                    // This attachment is not covered by any active rule
                    $nbskipped++;
                    continue;
                }
                $storedExt = ($ext === 'jpeg') ? 'jpeg' : $ext;
                $mime = isset(UpInvoiceFiles::EMAIL_ALLOWED_MIMES[$storedExt]) ? UpInvoiceFiles::EMAIL_ALLOWED_MIMES[$storedExt] : 'application/octet-stream';
                $allowedext = array($ext => $mime);
                $ruleLabel  = UpInvoiceFiles::ruleLabel($rule);
            }

            $reason = '';
            $errMsg = '';
            $fileId = UpInvoiceFiles::queueAttachment($this->db, $user, $filename, $content, $entity, $allowedext, 'email', $ruleLabel, $reason, $errMsg);

            if ($fileId < 0) {
                $this->error = 'UpInvoice: failed to queue attachment '.$filename.' - '.$errMsg;
                $this->errors[] = $this->error;
                return -1;
            } elseif ($fileId === 0) {
                $nbskipped++;
            } else {
                $nbqueued++;
                dol_syslog("ActionsUpinvoice::doCollectImapOneCollector attachment queued id=".$fileId." file=".$filename." rule=".$ruleLabel, LOG_INFO);
            }
        }

        if ($nbqueued === 0) {
            $this->resPrint = 'UpInvoice: email skipped (no attachment matched an active rule) from="'.$fromRaw.'" subject="'.$subjectRaw.'"';
            return 0;
        }

        $this->resPrint = 'UpInvoice: '.$nbqueued.' attachment(s) queued, '.$nbskipped.' skipped';

        return 1;
    }
}
