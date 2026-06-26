<?php
/* Copyright (C) 2026
 * Licensed under the GNU General Public License version 3
 */

/**
 * \file        class/upinvoicemailbox.class.php
 * \ingroup     upinvoice
 * \brief       Live IMAP mailbox access for the UpInvoice email tab
 */

dol_include_once('/upinvoice/class/upinvoicefiles.class.php');

/**
 * Class UpInvoiceMailbox — wraps IMAP access via the already-configured EmailCollector.
 *
 * Usage:
 *   $mb = new UpInvoiceMailbox($db);
 *   if ($mb->load() < 0) { ... error ... }
 *   if ($mb->connect('INBOX') < 0) { ... error ... }
 *   $emails = $mb->listEmails(50);
 *   $mb->disconnect();
 */
class UpInvoiceMailbox
{
    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var EmailCollector|null */
    protected $collector = null;

    /** @var resource|null  Native imap_open resource */
    protected $conn = null;

    /** @var bool  Using Webklex php-imap library */
    protected $useWebklex = false;

    /** @var mixed  Webklex folder object */
    protected $wkFolder = null;

    /** @var string  Currently open folder name */
    protected $currentFolder = '';

    /** @var int  UIDVALIDITY of current folder */
    protected $uidValidity = 0;

    /** Minimum size in bytes for image attachments; smaller files are treated as inline signatures and ignored. */
    const EMAIL_MIN_IMAGE_BYTES = 10240;

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Find the rowid of an active EmailCollector that has the UpInvoice hook operation.
     *
     * @param DoliDB $db Database handler
     * @return int >0 collector rowid, 0 if not found, <0 on DB error
     */
    public static function findCollectorId(DoliDB $db)
    {
        global $conf;

        $sql = "SELECT ec.rowid FROM ".MAIN_DB_PREFIX."emailcollector_emailcollector AS ec";
        $sql .= " INNER JOIN ".MAIN_DB_PREFIX."emailcollector_emailcollectoraction AS eca ON eca.fk_emailcollector = ec.rowid";
        $sql .= " WHERE eca.type = 'hook_upinvoice_import'";
        $sql .= " AND ec.entity IN (".getEntity('emailcollector').")";
        $sql .= " AND ec.status = 1";
        $sql .= " ORDER BY ec.rowid ASC ".$db->plimit(1);

        $resql = $db->query($sql);
        if (!$resql) {
            return -1;
        }
        if ($db->num_rows($resql) === 0) {
            $db->free($resql);
            return 0;
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return (int) $obj->rowid;
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Load the EmailCollector linked to UpInvoice.
     *
     * @return int >0 if OK, <0 if error
     */
    public function load()
    {
        require_once DOL_DOCUMENT_ROOT.'/emailcollector/class/emailcollector.class.php';

        $collectorId = self::findCollectorId($this->db);
        if ($collectorId <= 0) {
            $this->error = 'No active EmailCollector with hook_upinvoice_import found';
            return -1;
        }

        $col = new EmailCollector($this->db);
        $ret = $col->fetch($collectorId);
        if ($ret <= 0) {
            $this->error = 'EmailCollector::fetch failed: '.$col->error;
            return -1;
        }

        $this->collector = $col;
        return 1;
    }

    /**
     * Open a connection to a mailbox folder.
     *
     * @param string $folder  Folder to open (UTF-8)
     * @return int >0 OK, <0 error (sets $this->error)
     */
    public function connect($folder)
    {
        if (empty($this->collector)) {
            $this->error = 'Collector not loaded — call load() first';
            return -1;
        }

        $col = $this->collector;

        if ($col->acces_type == 1) {
            $this->error = 'oauth_not_supported';
            return -1;
        }

        $this->currentFolder = $folder;

        if (getDolGlobalString('MAIN_IMAP_USE_PHPIMAP')) {
            return $this->_connectWebklex($folder);
        }

        if (!function_exists('imap_open')) {
            $this->error = 'imap_extension_missing';
            return -1;
        }

        return $this->_connectNative($folder);
    }

    /**
     * Close the connection (if open).
     */
    public function disconnect()
    {
        if ($this->conn) {
            @imap_close($this->conn);
            $this->conn = null;
        }
        $this->wkFolder = null;
        $this->wkClient = null;
    }

    /**
     * List emails from source_directory AND target_directory, most recent first.
     * Only emails with ≥1 pdf/png/jpg/jpeg attachment are returned.
     *
     * @param int $limit Maximum number of emails to return
     * @return array|int Array of email rows or <0 on error.
     *   Each row: [uid, folder, from, subject, date, uidvalidity, attachments => [[filename, size, ext, imported]]]
     */
    public function listEmails($limit = 50)
    {
        if (empty($this->collector)) {
            $this->error = 'Collector not loaded';
            return -1;
        }

        $col = $this->collector;

        $folders = array();
        if (!empty($col->source_directory)) {
            $folders[] = $col->source_directory;
        }
        if (!empty($col->target_directory) && $col->target_directory !== $col->source_directory) {
            $folders[] = $col->target_directory;
        }

        if (empty($folders)) {
            $folders[] = 'INBOX';
        }

        $allEmails = array();

        if (getDolGlobalString('MAIN_IMAP_USE_PHPIMAP')) {
            // Webklex: single connection, iterate folders
            $ret = $this->connect($folders[0]); // establishes wkClient
            if ($ret < 0) {
                if ($this->error === 'oauth_not_supported') {
                    return -2;
                }
                return -1;
            }
            foreach ($folders as $folder) {
                $this->currentFolder = $folder;
                $rows = $this->_listEmailsWebklex($folder, $limit);
                if (is_array($rows)) {
                    $allEmails = array_merge($allEmails, $rows);
                }
            }
            $this->disconnect();
        } else {
            // Native IMAP: reconnect per folder
            foreach ($folders as $folder) {
                $ret = $this->connect($folder);
                if ($ret < 0) {
                    if ($this->error === 'oauth_not_supported') {
                        return -2;
                    }
                    if ($this->error === 'imap_extension_missing') {
                        return -1;
                    }
                    continue;
                }
                $rows = $this->_listEmailsNative($folder, $limit);
                $this->disconnect();
                if (is_array($rows)) {
                    $allEmails = array_merge($allEmails, $rows);
                }
            }
        }

        // Sort most recent first
        usort($allEmails, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return array_slice($allEmails, 0, $limit);
    }

    /**
     * Fetch raw attachment contents for a specific email UID in a given folder.
     *
     * @param string|int $uid      UID of the email
     * @param string     $folder   Folder name
     * @param int        $uidvalidity Expected UIDVALIDITY (0 = skip check)
     * @return array|int Array ['attachments' => [[filename, content, ext]]] or <0 error.
     *   Returns -2 with $this->error='stale_list' when UIDVALIDITY mismatch.
     */
    public function fetchAttachments($uid, $folder, $uidvalidity = 0)
    {
        $ret = $this->connect($folder);
        if ($ret < 0) {
            return -1;
        }

        if ($uidvalidity > 0 && $this->uidValidity > 0 && (int) $uidvalidity !== (int) $this->uidValidity) {
            $this->disconnect();
            $this->error = 'stale_list';
            return -2;
        }

        if (getDolGlobalString('MAIN_IMAP_USE_PHPIMAP')) {
            $result = $this->_fetchAttachmentsWebklex($uid);
        } else {
            $result = $this->_fetchAttachmentsNative($uid);
        }

        $this->disconnect();
        return $result;
    }

    // -------------------------------------------------------------------------
    // Native IMAP (imap_*) implementation
    // -------------------------------------------------------------------------

    /**
     * @param string $folder
     * @return int
     */
    protected function _connectNative($folder)
    {
        $col = $this->collector;
        $connectStr = $col->getConnectStringIMAP();
        $utf7folder = $col->getEncodedUtf7($folder);
        if ($utf7folder === false) {
            $utf7folder = $folder;
        }
        $mailbox = $connectStr.$utf7folder;

        $conn = @imap_open($mailbox, $col->login, $col->password, 0, 1);
        if (!$conn) {
            $this->error = 'imap_open failed for folder '.$folder.': '.imap_last_error();
            return -1;
        }
        $this->conn = $conn;
        $this->useWebklex = false;

        // Capture UIDVALIDITY
        $status = @imap_status($conn, $mailbox, SA_UIDVALIDITY);
        $this->uidValidity = ($status && isset($status->uidvalidity)) ? (int) $status->uidvalidity : 0;

        return 1;
    }

    /**
     * @param string $folder
     * @param int    $limit
     * @return array
     */
    protected function _listEmailsNative($folder, $limit)
    {
        global $conf;
        require_once DOL_DOCUMENT_ROOT.'/emailcollector/lib/emailcollector.lib.php';

        $col = $this->collector;
        // EmailCollector does not reliably expose ->entity; fall back to the current entity
        $matchEntity = !empty($col->entity) ? (int) $col->entity : (int) $conf->entity;
        $conn = $this->conn;
        $uidvalidity = $this->uidValidity;

        $count = imap_num_msg($conn);
        if ($count === 0) {
            return array();
        }

        // Fetch the most recent $limit message sequence numbers
        $start = max(1, $count - $limit + 1);
        $msgNums = range($count, $start, -1); // descending

        $emails = array();
        foreach ($msgNums as $msgNum) {
            $uid = imap_uid($conn, $msgNum);
            if (!$uid) {
                continue;
            }

            $overview = imap_fetch_overview($conn, (string) $uid, FT_UID);
            if (empty($overview)) {
                continue;
            }
            $ov = $overview[0];

            $from    = isset($ov->from) ? imap_utf8($ov->from) : '';
            $subject = isset($ov->subject) ? imap_utf8($ov->subject) : '';
            $date    = isset($ov->date) ? $ov->date : '';

            // Get attachment list (without downloading content)
            $pjList = getAttachments($uid, $conn);

            $attachments = array();
            foreach ($pjList as $pj) {
                $fname = isset($pj['filename']) ? $pj['filename'] : (isset($pj['name']) ? $pj['name'] : '');
                if (empty($fname)) {
                    continue;
                }
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (!array_key_exists($ext, UpInvoiceFiles::EMAIL_ALLOWED_MIMES)) {
                    continue;
                }
                $fsize = isset($pj['size']) ? (int) $pj['size'] : 0;
                // Skip small images that are likely inline email signatures
                $minImageBytes = (int) getDolGlobalInt('UPINVOICE_EMAIL_MIN_IMAGE_BYTES', self::EMAIL_MIN_IMAGE_BYTES);
                if ($ext !== 'pdf' && $fsize > 0 && $fsize < $minImageBytes) {
                    continue;
                }
                $importedRowid = UpInvoiceFiles::findRowidByNameSize($this->db, $fname, $fsize, $matchEntity);
                $attachments[] = array(
                    'filename'       => $fname,
                    'size'           => $fsize,
                    'ext'            => $ext,
                    'imported'       => ($importedRowid > 0),
                    'imported_rowid' => $importedRowid,
                    'pos'            => isset($pj['pos']) ? $pj['pos'] : null,
                    'type'           => isset($pj['type']) ? $pj['type'] : null,
                );
            }

            if (empty($attachments)) {
                continue;
            }

            $emails[] = array(
                'uid'         => (string) $uid,
                'folder'      => $folder,
                'from'        => $from,
                'subject'     => $subject,
                'date'        => $date,
                'uidvalidity' => $uidvalidity,
                'attachments' => $attachments,
            );
        }

        return $emails;
    }

    /**
     * @param string|int $uid
     * @return array|int
     */
    protected function _fetchAttachmentsNative($uid)
    {
        require_once DOL_DOCUMENT_ROOT.'/emailcollector/lib/emailcollector.lib.php';

        $conn = $this->conn;
        $pjList = getAttachments($uid, $conn);

        $minImageBytes = (int) getDolGlobalInt('UPINVOICE_EMAIL_MIN_IMAGE_BYTES', self::EMAIL_MIN_IMAGE_BYTES);
        $attachments = array();
        foreach ($pjList as $pj) {
            $fname = isset($pj['filename']) ? $pj['filename'] : (isset($pj['name']) ? $pj['name'] : '');
            if (empty($fname)) {
                continue;
            }
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            if (!array_key_exists($ext, UpInvoiceFiles::EMAIL_ALLOWED_MIMES)) {
                continue;
            }
            // Skip small images (likely inline signatures)
            $fsize = isset($pj['size']) ? (int) $pj['size'] : 0;
            if ($ext !== 'pdf' && $fsize > 0 && $fsize < $minImageBytes) {
                continue;
            }
            $content = getFileData($uid, (string) $pj['pos'], $pj['type'], $conn);
            if ($content === false) {
                continue;
            }
            $attachments[] = array(
                'filename' => $fname,
                'content'  => $content,
                'ext'      => $ext,
            );
        }

        return array('attachments' => $attachments);
    }

    // -------------------------------------------------------------------------
    // Webklex php-imap implementation
    // -------------------------------------------------------------------------

    /** @var mixed  Webklex client object */
    protected $wkClient = null;

    /**
     * Connect via Webklex, following the same pattern as EmailCollector::doCollectOneCollector.
     * Stores the connected client in $this->wkClient; folder is resolved per-query in _listEmailsWebklex.
     *
     * @param string $folder  (unused here — folder is resolved in listEmails)
     * @return int
     */
    protected function _connectWebklex($folder)
    {
        $col = $this->collector;

        try {
            require_once DOL_DOCUMENT_ROOT.'/includes/webklex/php-imap/vendor/autoload.php';

            $cm = new \Webklex\PHPIMAP\ClientManager();
            $client = $cm->make(array(
                'host'           => $col->host,
                'port'           => $col->port,
                'encryption'     => !empty($col->imap_encryption) ? $col->imap_encryption : false,
                'validate_cert'  => true,
                'protocol'       => 'imap',
                'username'       => $col->login,
                'password'       => $col->password,
                'authentication' => 'login',
            ));
            $client->connect();

            $this->wkClient   = $client;
            $this->wkFolder   = null;
            $this->useWebklex = true;
            $this->uidValidity = 0;

        } catch (\Throwable $e) {
            $this->error = 'Webklex connect error: '.$e->getMessage();
            return -1;
        }

        return 1;
    }

    /**
     * @param string $folder
     * @param int    $limit
     * @return array
     */
    protected function _listEmailsWebklex($folder, $limit)
    {
        global $conf;
        $col = $this->collector;
        // EmailCollector does not reliably expose ->entity; fall back to the current entity
        $matchEntity = !empty($col->entity) ? (int) $col->entity : (int) $conf->entity;

        try {
            $tmpsourcedir = $folder;
            if (!getDolGlobalString('MAIL_DISABLE_UTF7_ENCODE_OF_DIR') && $this->collector) {
                $enc = $this->collector->getEncodedUtf7($folder);
                if ($enc !== false) {
                    $tmpsourcedir = $enc;
                }
            }

            $folders = $this->wkClient->getFolders(false, $tmpsourcedir);
            if (empty($folders)) {
                return array();
            }
            $wkFolder = $folders[0];

            $messages = $wkFolder->messages()->all()->leaveUnread()->limit($limit)->setFetchOrder('desc')->get();

        } catch (\Throwable $e) {
            return array();
        }

        $emails = array();
        foreach ($messages as $message) {
            try {
                $uid     = (string) $message->getUid();
                $fromObj = $message->getFrom();
                $from    = ($fromObj && $fromObj->count()) ? (string) $fromObj->first() : '';
                $subjectObj = $message->getSubject();
                $subject = $subjectObj ? (string) $subjectObj->first() : '';
                $dateObj = $message->getDate();
                $date    = $dateObj ? (string) $dateObj->first() : '';

                $attachmentObjects = $message->getAttachments();
                $attachments = array();
                foreach ($attachmentObjects as $att) {
                    $fname = (string) $att->getName();
                    if (empty($fname) || $fname === 'undefined') {
                        continue;
                    }
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!array_key_exists($ext, UpInvoiceFiles::EMAIL_ALLOWED_MIMES)) {
                        continue;
                    }
                    $fsize = (int) $att->getSize();
                    // Skip small images that are likely inline email signatures
                    $minImageBytes = (int) getDolGlobalInt('UPINVOICE_EMAIL_MIN_IMAGE_BYTES', self::EMAIL_MIN_IMAGE_BYTES);
                    if ($ext !== 'pdf' && $fsize > 0 && $fsize < $minImageBytes) {
                        continue;
                    }
                    $importedRowid = UpInvoiceFiles::findRowidByNameSize($this->db, $fname, $fsize, $matchEntity);
                    $attachments[] = array(
                        'filename'       => $fname,
                        'size'           => $fsize,
                        'ext'            => $ext,
                        'imported'       => ($importedRowid > 0),
                        'imported_rowid' => $importedRowid,
                    );
                }

                if (empty($attachments)) {
                    continue;
                }

                $emails[] = array(
                    'uid'         => $uid,
                    'folder'      => $folder,
                    'from'        => $from,
                    'subject'     => $subject,
                    'date'        => $date,
                    'uidvalidity' => $this->uidValidity,
                    'attachments' => $attachments,
                );
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $emails;
    }

    /**
     * @param string|int $uid
     * @return array|int
     */
    protected function _fetchAttachmentsWebklex($uid)
    {
        try {
            // Re-resolve current folder
            $tmpsourcedir = $this->currentFolder;
            if (!getDolGlobalString('MAIL_DISABLE_UTF7_ENCODE_OF_DIR') && $this->collector) {
                $enc = $this->collector->getEncodedUtf7($this->currentFolder);
                if ($enc !== false) {
                    $tmpsourcedir = $enc;
                }
            }
            $folders = $this->wkClient->getFolders(false, $tmpsourcedir);
            if (empty($folders)) {
                $this->error = 'Folder not found: '.$this->currentFolder;
                return -1;
            }
            $wkFolder = $folders[0];

            $message = $wkFolder->query()->getMessageByUid($uid);
            if (!$message) {
                $this->error = 'Message UID '.$uid.' not found';
                return -1;
            }

            $attachmentObjects = $message->getAttachments();
            $attachments = array();
            $minImageBytes = (int) getDolGlobalInt('UPINVOICE_EMAIL_MIN_IMAGE_BYTES', self::EMAIL_MIN_IMAGE_BYTES);
            foreach ($attachmentObjects as $att) {
                $fname = (string) $att->getName();
                if (empty($fname) || $fname === 'undefined') {
                    continue;
                }
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (!array_key_exists($ext, UpInvoiceFiles::EMAIL_ALLOWED_MIMES)) {
                    continue;
                }
                $content = $att->getContent();
                if ($content === false || $content === null) {
                    continue;
                }
                // Skip small images (likely inline signatures)
                $fsize = strlen($content);
                if ($ext !== 'pdf' && $fsize > 0 && $fsize < $minImageBytes) {
                    continue;
                }
                $attachments[] = array(
                    'filename' => $fname,
                    'content'  => $content,
                    'ext'      => $ext,
                );
            }

            return array('attachments' => $attachments);

        } catch (\Throwable $e) {
            $this->error = 'Webklex fetchAttachments error: '.$e->getMessage();
            return -1;
        }
    }
}
