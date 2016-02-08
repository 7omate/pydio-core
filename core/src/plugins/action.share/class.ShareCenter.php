<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */

defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * @package AjaXplorer_Plugins
 * @subpackage Action
 */
class ShareCenter extends AJXP_Plugin
{
    /**
     * @var AbstractAccessDriver
     */
    private $accessDriver;
    /**
     * @var Repository
     */
    private $repository;
    private $urlBase;

    /**
     * @var ShareStore
     */
    private $shareStore;

    /**
     * @var PublicAccessManager
     */
    private $publicAccessManager;

    /**
     * @var MetaWatchRegister
     */
    private $watcher = false;

    /**************************/
    /* PLUGIN LIFECYCLE METHODS
    /**************************/
    /**
     * AJXP_Plugin initializer
     * @param array $options
     */
    public function init($options)
    {
        parent::init($options);
        $this->repository = ConfService::getRepository();
        if (!is_a($this->repository->driverInstance, "AjxpWrapperProvider")) {
            return;
        }
        $this->accessDriver = $this->repository->driverInstance;
        $this->urlBase = "pydio://". $this->repository->getId();
        if (array_key_exists("meta.watch", AJXP_PluginsService::getInstance()->getActivePlugins())) {
            $this->watcher = AJXP_PluginsService::getInstance()->getPluginById("meta.watch");
        }
    }

    /**
     * Extend parent
     * @param DOMNode $contribNode
     */
    protected function parseSpecificContributions(&$contribNode)
    {
        parent::parseSpecificContributions($contribNode);
        $disableSharing = false;
        $downloadFolder = ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER");
        if ( empty($downloadFolder) || (!is_dir($downloadFolder) || !is_writable($downloadFolder))) {
            $this->logError("Warning on public links, $downloadFolder is not writeable!", array("folder" => $downloadFolder, "is_dir" => is_dir($downloadFolder),"is_writeable" => is_writable($downloadFolder)));
        }

        $xpathesToRemove = array();

        if( strpos(ConfService::getRepository()->getAccessType(), "ajxp_") === 0){

            $xpathesToRemove[] = 'action[@name="share-file-minisite"]';
            $xpathesToRemove[] = 'action[@name="share-folder-minisite-public"]';
            $xpathesToRemove[] = 'action[@name="share-edit-shared"]';

        }else if (AuthService::usersEnabled()) {

            $loggedUser = AuthService::getLoggedUser();
            if ($loggedUser != null && AuthService::isReservedUserId($loggedUser->getId())) {
                $disableSharing = true;
            }

        } else {

            $disableSharing = true;

        }
        if ($disableSharing) {
            // All share- actions
            $xpathesToRemove[] = 'action[contains(@name, "share-")]';
        }else{
            $folderSharingAllowed = $this->getAuthorization("folder", "any");
            $fileSharingAllowed = $this->getAuthorization("file");
            if($fileSharingAllowed === false){
                // Share file button
                $xpathesToRemove[] = 'action[@name="share-file-minisite"]';
            }
            if(!$folderSharingAllowed){
                // Share folder button
                $xpathesToRemove[] = 'action[@name="share-folder-minisite-public"]';
            }
        }

        foreach($xpathesToRemove as $xpath){
            $actionXpath=new DOMXPath($contribNode->ownerDocument);
            $nodeList = $actionXpath->query($xpath, $contribNode);
            foreach($nodeList as $shareActionNode){
                $contribNode->removeChild($shareActionNode);
            }
        }
    }

    /**************************/
    /* UTILS & ACCESSORS
    /**************************/
    /**
     * Compute right to create shares based on plugin options
     * @param string $nodeType "file"|"folder"
     * @param string $shareType
     * @return bool
     */
    protected function getAuthorization($nodeType, $shareType = "any"){
        if($nodeType == "file"){
            return $this->getFilteredOption("ENABLE_FILE_PUBLIC_LINK") !== false;
        }else{
            $opt = $this->getFilteredOption("ENABLE_FOLDER_SHARING");
            if($shareType == "minisite"){
                return ($opt == "minisite" || $opt == "both");
            }else if($shareType == "workspace"){
                return ($opt == "workspace" || $opt == "both");
            }else{
                return ($opt !== "disable");
            }
        }
    }

    /**
     * @return ShareCenter
     */
    public static function getShareCenter(){
        return AJXP_PluginsService::findPluginById("action.share");
    }

    public static function currentContextIsLinkDownload(){
        return (isSet($_GET["dl"]) && isSet($_GET["dl"]) == "true");
    }

    /**
     * Check if the hash seems to correspond to the serialized data.
     * Kept there only for backward compatibility
     * @static
     * @param String $outputData serialized data
     * @param String $hash Id to check
     * @return bool
     */
    public static function checkHash($outputData, $hash)
    {
        // Never return false, otherwise it can break listing due to hardcore exit() call;
        // Rechecked later
        return true;

        //$full = md5($outputData);
        //return (!empty($hash) && strpos($full, $hash."") === 0);
    }


    /**
     * @return ShareStore
     */
    public function getShareStore(){
        if(!isSet($this->shareStore)){
            require_once("class.ShareStore.php");
            $hMin = 32;
            if(isSet($this->repository)){
                $hMin = $this->getFilteredOption("HASH_MIN_LENGTH", $this->repository);
            }
            $this->shareStore = new ShareStore(
                ConfService::getCoreConf("PUBLIC_DOWNLOAD_FOLDER"),
                $hMin
            );
        }
        return $this->shareStore;
    }

    /**
     * @return PublicAccessManager
     */
    public function getPublicAccessManager(){

        if(!isSet($this->publicAccessManager)){
            require_once("class.PublicAccessManager.php");
            $options = array(
                "USE_REWRITE_RULE" => $this->getFilteredOption("USE_REWRITE_RULE", $this->repository) == true
            );
            $this->publicAccessManager = new PublicAccessManager($options);
        }
        return $this->publicAccessManager;

    }

    /**
     * @param AJXP_Node $ajxpNode
     * @return boolean
     */
    public function isShared($ajxpNode)
    {
        $this->getShareStore()->getMetaManager()->getSharesFromMeta($ajxpNode, $shares, true);
        return count($shares) > 0;
    }

    /**************************/
    /* CALLBACKS FOR ACTIONS
    /**************************/
    /**
     * Added as preprocessor on Download action to handle download Counter.
     * @param string $action
     * @param array $httpVars
     * @param array $fileVars
     * @throws Exception
     */
    public function preProcessDownload($action, &$httpVars, &$fileVars){
        if(isSet($_SESSION["CURRENT_MINISITE"])){
            $this->logDebug(__FUNCTION__, "Do something here!");
            $hash = $_SESSION["CURRENT_MINISITE"];
            $share = $this->getShareStore()->loadShare($hash);
            if(!empty($share)){
                if($this->getShareStore()->isShareExpired($hash, $share)){
                    throw new Exception('Link is expired');
                }
                if(!empty($share["DOWNLOAD_LIMIT"])){
                    $this->getShareStore()->incrementDownloadCounter($hash);
                }
            }
        }
    }

    /**
     * Main callback for all share- actions.
     * @param string $action
     * @param array $httpVars
     * @param array $fileVars
     * @return null
     * @throws Exception
     */
    public function switchAction($action, $httpVars, $fileVars)
    {
        if (strpos($action, "sharelist") === false && !isSet($this->accessDriver)) {
            throw new Exception("Cannot find access driver!");
        }


        if (strpos($action, "sharelist") === false && $this->accessDriver->getId() == "access.demo") {
            $errorMessage = "This is a demo, all 'write' actions are disabled!";
            if ($httpVars["sub_action"] == "delegate_repo") {
                return AJXP_XMLWriter::sendMessage(null, $errorMessage, false);
            } else {
                print($errorMessage);
            }
            return null;
        }


        switch ($action) {

            //------------------------------------
            // SHARING FILE OR FOLDER
            //------------------------------------
            case "share":
                $subAction = (isSet($httpVars["sub_action"])?$httpVars["sub_action"]:"");
                if(empty($subAction) && isSet($httpVars["simple_share_type"])){
                    $subAction = "create_minisite";
                    if(!isSet($httpVars["simple_right_read"]) && !isSet($httpVars["simple_right_download"])){
                        $httpVars["simple_right_read"] = $httpVars["simple_right_download"] = "true";
                    }
                }
                $userSelection = new UserSelection(ConfService::getRepository(), $httpVars);
                $ajxpNode = $userSelection->getUniqueNode();
                if (!file_exists($ajxpNode->getUrl())) {
                    throw new Exception("Cannot share a non-existing file: ".$ajxpNode->getUrl());
                }
                $newMeta = null;
                $maxdownload = abs(intval($this->getFilteredOption("FILE_MAX_DOWNLOAD", $this->repository)));
                $download = isset($httpVars["downloadlimit"]) ? abs(intval($httpVars["downloadlimit"])) : 0;
                if ($maxdownload == 0) {
                    $httpVars["downloadlimit"] = $download;
                } elseif ($maxdownload > 0 && $download == 0) {
                    $httpVars["downloadlimit"] = $maxdownload;
                } else {
                    $httpVars["downloadlimit"] = min($download,$maxdownload);
                }
                $maxexpiration = abs(intval($this->getFilteredOption("FILE_MAX_EXPIRATION", $this->repository)));
                $expiration = isset($httpVars["expiration"]) ? abs(intval($httpVars["expiration"])) : 0;
                if ($maxexpiration == 0) {
                    $httpVars["expiration"] = $expiration;
                } elseif ($maxexpiration > 0 && $expiration == 0) {
                    $httpVars["expiration"] = $maxexpiration;
                } else {
                    $httpVars["expiration"] = min($expiration,$maxexpiration);
                }
                $forcePassword = $this->getFilteredOption("SHARE_FORCE_PASSWORD", $this->repository);
                $httpHash = null;
                $originalHash = null;

                if ($subAction == "delegate_repo") {
                    header("Content-type:text/plain");
                    $auth = $this->getAuthorization("folder", "workspace");
                    if(!$auth){
                        print 103;
                        break;
                    }
                    $result = $this->createSharedRepository($httpVars, $this->repository, $this->accessDriver);
                    if (is_a($result, "Repository")) {
                        $newMeta = array("id" => $result->getUniqueId(), "type" => "repository");
                        $numResult = 200;
                    } else {
                        $numResult = $result;
                    }
                    print($numResult);
                } else if ($subAction == "create_minisite") {
                    header("Content-type:text/plain");
                    if(isSet($httpVars["hash"]) && !empty($httpVars["hash"])) $httpHash = $httpVars["hash"];
                    if(isSet($httpVars["simple_share_type"])){
                        $httpVars["create_guest_user"] = "true";
                        if($httpVars["simple_share_type"] == "private" && !isSet($httpVars["guest_user_pass"])){
                            throw new Exception("Please provide a guest_user_pass for private link");
                        }
                    }
                    if($forcePassword && (
                        (isSet($httpVars["create_guest_user"]) && $httpVars["create_guest_user"] == "true" && empty($httpVars["guest_user_pass"]))
                        || (isSet($httpVars["guest_user_id"]) && isSet($httpVars["guest_user_pass"]) && $httpVars["guest_user_pass"] == "")
                        )){
                        $mess = ConfService::getMessages();
                        throw new Exception($mess["share_center.175"]);
                    }
                    $res = $this->createSharedMinisite($httpVars, $this->repository, $this->accessDriver);
                    if (!is_array($res)) {
                        $url = $res;
                    } else {
                        list($hash, $url) = $res;
                        $newMeta = array("id" => $hash, "type" => "minisite");
                        if($httpHash != null && $hash != $httpHash){
                            $originalHash = $httpHash;
                        }
                    }
                    print($url);
                }
                if ($newMeta != null && $ajxpNode->hasMetaStore() && !$ajxpNode->isRoot()) {
                    $this->getShareStore()->getMetaManager()->addShareInMeta($ajxpNode, $newMeta["type"], $newMeta["id"], $originalHash);
                }
                AJXP_Controller::applyHook("msg.instant", array("<reload_shared_elements/>", ConfService::getRepository()->getId()));
                // as the result can be quite small (e.g error code), make sure it's output in case of OB active.
                flush();

                break;

            case "toggle_link_watch":

                $userSelection = new UserSelection($this->repository, $httpVars);
                $shareNode = $selectedNode = $userSelection->getUniqueNode();
                $watchValue = $httpVars["set_watch"] == "true" ? true : false;
                $folder = false;
                if (isSet($httpVars["element_type"]) && $httpVars["element_type"] == "folder") {
                    $folder = true;
                    $selectedNode = new AJXP_Node("pydio://". AJXP_Utils::sanitize($httpVars["repository_id"], AJXP_SANITIZE_ALPHANUM)."/");
                }
                $this->getShareStore()->getMetaManager()->getSharesFromMeta($shareNode, $shares, false);
                if(!count($shares)){
                    break;
                }

                if(isSet($httpVars["element_id"]) && isSet($shares[$httpVars["element_id"]])){
                    $elementId = $httpVars["element_id"];
                }else{
                    $sKeys = array_keys($shares);
                    $elementId = $sKeys[0];
                }

                if ($this->watcher !== false) {
                    if (!$folder) {
                        if ($watchValue) {
                            $this->watcher->setWatchOnFolder(
                                $selectedNode,
                                AuthService::getLoggedUser()->getId(),
                                MetaWatchRegister::$META_WATCH_USERS_READ,
                                array($elementId)
                            );
                        } else {
                            $this->watcher->removeWatchFromFolder(
                                $selectedNode,
                                AuthService::getLoggedUser()->getId(),
                                true,
                                $elementId
                            );
                        }
                    } else {
                        if ($watchValue) {
                            $this->watcher->setWatchOnFolder(
                                $selectedNode,
                                AuthService::getLoggedUser()->getId(),
                                MetaWatchRegister::$META_WATCH_BOTH
                            );
                        } else {
                            $this->watcher->removeWatchFromFolder(
                                $selectedNode,
                                AuthService::getLoggedUser()->getId());
                        }
                    }
                }
                $mess = ConfService::getMessages();
                AJXP_XMLWriter::header();
                AJXP_XMLWriter::sendMessage($mess["share_center.47"], null);
                AJXP_XMLWriter::close();

            break;

            case "load_shared_element_data":

                $node = null;
                if(isSet($httpVars["hash"])){
                    $t = "minisite";
                    if(isSet($httpVars["element_type"]) && $httpVars["element_type"] == "file") $t = "file";
                    $parsedMeta = array($httpVars["hash"] => array("type" => $t));
                }else{
                    $file = AJXP_Utils::decodeSecureMagic($httpVars["file"]);
                    $node = new AJXP_Node($this->urlBase.$file);
                    $this->getShareStore()->getMetaManager()->getSharesFromMeta($node, $parsedMeta, true);
                }

                $flattenJson = false;
                $jsonData = array();
                foreach($parsedMeta as $shareId => $shareMeta){

                    $jsonData[] = $this->shareToJson($shareId, $shareMeta, $node);
                    if($shareMeta["type"] != "file"){
                        $flattenJson = true;
                    }

                }
                header("Content-type:application/json");
                if($flattenJson && count($jsonData)) $jsonData = $jsonData[0];
                echo json_encode($jsonData);

            break;

            case "unshare":

                if(isSet($httpVars["hash"])){

                    $res = $this->getShareStore()->deleteShare($httpVars["element_type"], $httpVars["hash"]);
                    if($res !== false){
                        AJXP_XMLWriter::header();
                        AJXP_XMLWriter::sendMessage("Successfully unshared element", null);
                        AJXP_XMLWriter::close();
                    }

                }else{

                    $userSelection = new UserSelection($this->repository, $httpVars);
                    $ajxpNode = $userSelection->getUniqueNode();
                    $this->getShareStore()->getMetaManager()->getSharesFromMeta($ajxpNode, $shares, false);
                    if(count($shares)){
                        if(isSet($httpVars["element_id"]) && isSet($shares[$httpVars["element_id"]])){
                            $elementId = $httpVars["element_id"];
                        }else{
                            $sKeys = array_keys($shares);
                            $elementId = $sKeys[0];
                        }
                        if(isSet($shares[$elementId]) && isSet($shares[$elementId]["type"])){
                            $t = $shares[$elementId]["type"];
                        }else{
                            $t = "file";
                        }
                        $this->getShareStore()->deleteShare($t, $elementId);
                        $this->getShareStore()->getMetaManager()->removeShareFromMeta($ajxpNode, $elementId);
                        AJXP_Controller::applyHook("msg.instant", array("<reload_shared_elements/>", ConfService::getRepository()->getId()));
                    }

                }
                break;

            case "reset_counter":

                if(isSet($httpVars["hash"])){

                    $userId = AuthService::getLoggedUser()->getId();
                    if(isSet($httpVars["owner_id"]) && $httpVars["owner_id"] != $userId){
                        if(!AuthService::getLoggedUser()->isAdmin()){
                            throw new Exception("You are not allowed to access this resource");
                        }
                        $userId = $httpVars["owner_id"];
                    }
                    $this->getShareStore()->resetDownloadCounter($httpVars["hash"], $userId);

                }else{

                    $userSelection = new UserSelection($this->repository, $httpVars);
                    $ajxpNode = $userSelection->getUniqueNode();
                    $metadata = $this->getShareStore()->getMetaManager()->getNodeMeta($ajxpNode);
                    if(!isSet($metadata["shares"]) || !is_array($metadata["shares"])){
                        return null;
                    }
                    if ( isSet($httpVars["element_id"]) && isSet($metadata["shares"][$httpVars["element_id"]])) {
                        $this->getShareStore()->resetDownloadCounter($httpVars["element_id"], $httpVars["owner_id"]);
                    }else{
                        $keys = array_keys($metadata["shares"]);
                        foreach($keys as $key){
                            $this->getShareStore()->resetDownloadCounter($key, null);
                        }
                    }

                }

            break;

            case "update_shared_element_data":

                if(!in_array($httpVars["p_name"], array("counter", "tags"))){
                    return null;
                }
                $hash = AJXP_Utils::decodeSecureMagic($httpVars["element_id"]);
                $userSelection = new UserSelection($this->repository, $httpVars);
                $ajxpNode = $userSelection->getUniqueNode();
                if($this->getShareStore()->shareIsLegacy($hash)){
                    // Store in metadata
                    $metadata = $this->getShareStore()->getMetaManager()->getNodeMeta($ajxpNode);
                    if (isSet($metadata["shares"][$httpVars["element_id"]])) {
                        if (!is_array($metadata["shares"][$httpVars["element_id"]])) {
                            $metadata["shares"][$httpVars["element_id"]] = array();
                        }
                        $metadata["shares"][$httpVars["element_id"]][$httpVars["p_name"]] = $httpVars["p_value"];
                        $this->getShareStore()->getMetaManager()->setNodeMeta($ajxpNode, $metadata);
                    }
                }else{
                    $this->getShareStore()->updateShareProperty($hash, $httpVars["p_name"], $httpVars["p_value"]);
                }


                break;

            case "sharelist-load":

                $parentRepoId = isset($httpVars["parent_repository_id"]) ? $httpVars["parent_repository_id"] : "";
                $userContext = $httpVars["user_context"];
                $currentUser = true;
                if($userContext == "global" && AuthService::getLoggedUser()->isAdmin()){
                    $currentUser = false;
                }else if($userContext == "user" && AuthService::getLoggedUser()->isAdmin() && !empty($httpVars["user_id"])){
                    $currentUser = AJXP_Utils::sanitize($httpVars["user_id"], AJXP_SANITIZE_EMAILCHARS);
                }
                $nodes = $this->listSharesAsNodes("/data/repositories/$parentRepoId/shares", $currentUser, $parentRepoId);

                AJXP_XMLWriter::header();
                if($userContext == "current"){
                    AJXP_XMLWriter::sendFilesListComponentConfig('<columns template_name="ajxp_user.shares">
                    <column messageId="ajxp_conf.8" attributeName="ajxp_label" sortType="String"/>
                    <column messageId="share_center.132" attributeName="shared_element_parent_repository_label" sortType="String"/>
                    <column messageId="3" attributeName="share_type_readable" sortType="String"/>
                    </columns>');
                }else{
                    AJXP_XMLWriter::sendFilesListComponentConfig('<columns switchDisplayMode="list" switchGridMode="filelist" template_name="ajxp_conf.repositories">
                    <column messageId="ajxp_conf.8" attributeName="ajxp_label" sortType="String"/>
                    <column messageId="share_center.159" attributeName="owner" sortType="String"/>
                    <column messageId="3" attributeName="share_type_readable" sortType="String"/>
                    <column messageId="share_center.52" attributeName="share_data" sortType="String"/>
                    </columns>');
                }

                foreach($nodes as $node){
                    AJXP_XMLWriter::renderAjxpNode($node);
                }
                AJXP_XMLWriter::close();

            break;

            case "sharelist-clearExpired":

                $accessType = ConfService::getRepository()->getAccessType();
                $currentUser  = ($accessType != "ajxp_conf" && $accessType != "ajxp_admin");
                $count = $this->getShareStore()->clearExpiredFiles($currentUser);
                AJXP_XMLWriter::header();
                if($count){
                    AJXP_XMLWriter::sendMessage("Removed ".count($count)." expired links", null);
                }else{
                    AJXP_XMLWriter::sendMessage("Nothing to do", null);
                }
                AJXP_XMLWriter::close();

            break;

            default:
            break;
        }

        return null;

    }

    /**************************/
    /* CALLBACKS FOR HOOKS
    /**************************/
    /**
     * Hook node.info
     * @param AJXP_Node $ajxpNode
     * @return void
     */
    public function nodeSharedMetadata(&$ajxpNode)
    {
        if(empty($this->accessDriver) || $this->accessDriver->getId() == "access.imap") return;

        $this->getShareStore()->getMetaManager()->getSharesFromMeta($ajxpNode, $shares, true);
        if(!empty($shares) && count($shares)){
            $merge = array(
                "ajxp_shared"      => "true",
                "overlay_icon"     => "shared.png",
                "overlay_class"    => "icon-share-sign"
            );
            // Backward compat, until we rework client-side
            $sKeys = array_keys($shares);
            if($shares[$sKeys[0]]["type"] == "minisite"){
                if($ajxpNode->isLeaf()){
                    $merge["ajxp_shared_minisite"] = "file";
                }else{
                    $merge["ajxp_shared_minisite"] = "public";
                }
            }else if($shares[$sKeys[0]]["type"] == "file"){
                $merge["ajxp_shared_publiclet"] = "true";
            }
            $ajxpNode->mergeMetadata($merge, true);
        }
        return;
    }

    /**
     * Hook node.change
     * @param AJXP_Node $oldNode
     * @param AJXP_Node $newNode
     * @param bool $copy
     */
    public function updateNodeSharedData($oldNode=null, $newNode=null, $copy = false){

        if($oldNode != null && !$copy){
            $this->logDebug("Should update node");

            $delete = false;
            if($newNode == null) {
                $delete = true;
            }else{
                $repo = $newNode->getRepository();
                $recycle = $repo->getOption("RECYCLE_BIN");
                if(!empty($recycle) && strpos($newNode->getPath(), $recycle) === 1){
                    $delete = true;
                }
            }
            $shareStore = $this->getShareStore();
            // Find shares in children
            $result = $shareStore->getMetaManager()->collectSharesIncludingChildren($oldNode);
            foreach($result as $path => $metadata){
                $changeOldNode = new AJXP_Node("pydio://".$oldNode->getRepositoryId().$path);

                foreach($metadata as $ownerId => $meta){
                    if(!isSet($meta["shares"])){
                        // Old school, migrate?
                        continue;
                    }
                    $changeOldNode->setUser($ownerId);
                    /// do something
                    $changeNewNode = null;
                    if(!$delete){
                        $newPath = preg_replace('#^'.preg_quote($oldNode->getPath(), '#').'#', $newNode->getPath(), $path);
                        $changeNewNode = new AJXP_Node("pydio://".$newNode->getRepositoryId().$newPath);
                        $changeNewNode->setUser($ownerId);
                    }
                    $newShares = $shareStore->moveSharesFromMeta($meta["shares"], $delete?"delete":"move", $changeOldNode, $changeNewNode);
                    $shareStore->getMetaManager()->clearNodeMeta($changeOldNode);
                    if(!$delete && count($newShares)){
                        $shareStore->getMetaManager()->setNodeMeta($changeNewNode, array("shares" => $newShares));
                    }
                }
            }
            return;
        }
    }

    /**
     * Hook user.after_delete
     * make sure to clear orphan shares
     * @param String $userId
     */
    public function cleanUserShares($userId){
        $shares = $this->getShareStore()->listShares($userId);
        foreach($shares as $hash => $data){
            $this->getShareStore()->deleteShare($data['SHARE_TYPE'], $hash);
        }
    }


    /************************************/
    /* EVENTS FORWARDING BETWEEN
    /* PARENTS AND CHILDREN WORKSPACES
    /************************************/
    /**
     * @param AJXP_Node $node
     * @param String|null $direction "UP", "DOWN"
     * @return array()
     */
    private function findMirrorNodesInShares($node, $direction){
        $result = array();
        if($direction !== "UP"){
            $upmetas = array();
            $this->getShareStore()->getMetaManager()->collectSharesInParent($node, $upmetas);
            foreach($upmetas as $metadata){
                if (is_array($metadata) && !empty($metadata["shares"])) {
                    foreach($metadata["shares"] as $sId => $sData){
                        $type = $sData["type"];
                        if($type == "file") continue;
                        $wsId = $sId;
                        if($type == "minisite"){
                            $minisiteData = $this->getShareStore()->loadShare($sId);
                            $wsId = $minisiteData["REPOSITORY"];
                        }
                        $sharedNode = $metadata["SOURCE_NODE"];
                        $sharedPath = substr($node->getPath(), strlen($sharedNode->getPath()));
                        $sharedNodeUrl = $node->getScheme() . "://".$wsId.$sharedPath;
                        $result[$wsId] = array(new AJXP_Node($sharedNodeUrl), "DOWN");
                        $this->logDebug('MIRROR NODES', 'Found shared in parent - register node '.$sharedNodeUrl);
                    }
                }
            }
        }
        if($direction !== "DOWN"){
            if($node->getRepository()->hasParent()){
                $parentRepoId = $node->getRepository()->getParentId();
                $parentRepository = ConfService::getRepositoryById($parentRepoId);
                if(!empty($parentRepository) && !$parentRepository->isTemplate){
                    $currentRoot = $node->getRepository()->getOption("PATH");
                    $owner = $node->getRepository()->getOwner();
                    $resolveUser = null;
                    if($owner != null){
                        $resolveUser = ConfService::getConfStorageImpl()->createUserObject($owner);
                    }
                    $parentRoot = $parentRepository->getOption("PATH", false, $resolveUser);
                    $relative = substr($currentRoot, strlen($parentRoot));
                    $parentNodeURL = $node->getScheme()."://".$parentRepoId.$relative.$node->getPath();
                    $this->logDebug("action.share", "Should trigger on ".$parentNodeURL);
                    $parentNode = new AJXP_Node($parentNodeURL);
                    if($owner != null) $parentNode->setUser($owner);
                    $result[$parentRepoId] = array($parentNode, "UP");
                }
            }
        }
        return $result;
    }

    private function applyForwardEvent($fromMirrors = null, $toMirrors = null, $copy = false, $direction = null){
        if($fromMirrors === null){
            // Create
            foreach($toMirrors as $mirror){
                list($node, $direction) = $mirror;
                AJXP_Controller::applyHook("node.change", array(null, $node, false, $direction), true);
            }
        }else if($toMirrors === null){
            foreach($fromMirrors as $mirror){
                list($node, $direction) = $mirror;
                AJXP_Controller::applyHook("node.change", array($node, null, false, $direction), true);
            }
        }else{
            foreach($fromMirrors as $repoId => $mirror){
                list($fNode, $fDirection) = $mirror;
                if(isSet($toMirrors[$repoId])){
                    list($tNode, $tDirection) = $toMirrors[$repoId];
                    unset($toMirrors[$repoId]);
                    try{
                        AJXP_Controller::applyHook("node.change", array($fNode, $tNode, $copy, $fDirection), true);
                    }catch(Exception $e){
                        $this->logError(__FUNCTION__, "Error while applying node.change hook (".$e->getMessage().")");
                    }
                }else{
                    try{
                    AJXP_Controller::applyHook("node.change", array($fNode, null, $copy, $fDirection), true);
                    }catch(Exception $e){
                        $this->logError(__FUNCTION__, "Error while applying node.change hook (".$e->getMessage().")");
                    }
                }
            }
            foreach($toMirrors as $mirror){
                list($tNode, $tDirection) = $mirror;
                try{
                AJXP_Controller::applyHook("node.change", array(null, $tNode, $copy, $tDirection), true);
                }catch(Exception $e){
                    $this->logError(__FUNCTION__, "Error while applying node.change hook (".$e->getMessage().")");
                }
            }
        }

    }

    /**
     * @param AJXP_Node $fromNode
     * @param AJXP_Node $toNode
     * @param bool $copy
     * @param String $direction
     */
    public function forwardEventToShares($fromNode=null, $toNode=null, $copy = false, $direction=null){

        if(empty($direction) && $this->getFilteredOption("FORK_EVENT_FORWARDING")){
            AJXP_Controller::applyActionInBackground(
                ConfService::getRepository()->getId(),
                "forward_change_event",
                array(
                    "from" => $fromNode === null ? "" : $fromNode->getUrl(),
                    "to" =>   $toNode === null ? "" : $toNode->getUrl(),
                    "copy" => $copy ? "true" : "false",
                    "direction" => $direction
                ));
            return;
        }

        $fromMirrors = null;
        $toMirrors = null;
        if($fromNode != null){
            $fromMirrors = $this->findMirrorNodesInShares($fromNode, $direction);
        }
        if($toNode != null){
            $toMirrors = $this->findMirrorNodesInShares($toNode, $direction);
        }

        $this->applyForwardEvent($fromMirrors, $toMirrors, $copy, $direction);
        if(count($fromMirrors) || count($toMirrors)){
            // Make sure to switch back to correct repository in memory
            if($fromNode != null) {
                $fromNode->getRepository()->driverInstance = null;
                $fromNode->setDriver(null);
                $fromNode->getDriver();
            }else if($toNode != null){
                $toNode->getRepository()->driverInstance = null;
                $toNode->setDriver(null);
                $toNode->getDriver();
            }
        }
    }

    public function forwardEventToSharesAction($actionName, $httpVars, $fileVars){

        $fromMirrors = null;
        $toMirrors = null;
        $fromNode = $toNode = null;
        if(!empty($httpVars["from"])){
            $fromNode = new AJXP_Node($httpVars["from"]);
            $fromMirrors = $this->findMirrorNodesInShares($fromNode, $httpVars["direction"]);
        }
        if(!empty($httpVars["to"])){
            $toNode = new AJXP_Node($httpVars["to"]);
            $toMirrors = $this->findMirrorNodesInShares($toNode, $httpVars["direction"]);
        }
        $this->applyForwardEvent($fromMirrors, $toMirrors, ($httpVars["copy"] === "true"), $httpVars["direction"]);
        if(count($fromMirrors) || count($toMirrors)){
            // Make sure to switch back to correct repository in memory
            if($fromNode != null) {
                $fromNode->getRepository()->driverInstance = null;
                $fromNode->setDriver(null);
                $fromNode->getDriver();
            }else if($toNode != null){
                $toNode->getRepository()->driverInstance = null;
                $toNode->setDriver(null);
                $toNode->getDriver();
            }
        }
    }


    /**************************/
    /* BOOTLOADERS FOR LINKS
    /**************************/
    public static function loadMinisite($data, $hash = '', $error = null)
    {
        include_once("class.MinisiteRenderer.php");
        MinisiteRenderer::loadMinisite($data, $hash, $error);
    }

    public static function loadShareByHash($hash){
        AJXP_Logger::debug(__CLASS__, __FUNCTION__, "Do something");
        AJXP_PluginsService::getInstance()->initActivePlugins();
        if(isSet($_GET["lang"])){
            ConfService::setLanguage($_GET["lang"]);
        }
        $shareCenter = self::getShareCenter();
        $data = $shareCenter->getShareStore()->loadShare($hash);
        $mess = ConfService::getMessages();
        if($shareCenter->getShareStore()->isShareExpired($hash, $data)){
            AuthService::disconnect();
            self::loadMinisite(array(), $hash, $mess["share_center.165"]);
            return;
        }
        if(!empty($data) && is_array($data)){
            if(isSet($data["SECURITY_MODIFIED"]) && $data["SECURITY_MODIFIED"] === true){
                header("HTTP/1.0 401 Not allowed, script was modified");
                exit();
            }
            if($data["SHARE_TYPE"] == "minisite"){
                self::loadMinisite($data, $hash);
            }else{
                self::loadPubliclet($data);
            }
        }else{
            self::loadMinisite(array(), $hash, $mess["share_center.166"]);
        }

    }

    /**
     * @static
     * @param array $data
     * @return void
     */
    public static function loadPubliclet($data)
    {
        require_once("class.LegacyPubliclet.php");
        $shareCenter = self::getShareCenter();
        $options = $shareCenter->getConfigs();
        $shareStore = $shareCenter->getShareStore();
        LegacyPubliclet::render($data, $options, $shareStore);
    }


    /**************************/
    /* CREATE / EDIT SHARES
    /**************************/
    /**
     * @param String $repoId
     * @param $mixUsersAndGroups
     * @param $currentFileUrl
     * @return array
     */
    public function computeSharedRepositoryAccessRights($repoId, $mixUsersAndGroups, $currentFileUrl = null)
    {
        $roles = AuthService::getRolesForRepository($repoId);
        $sharedEntries = $sharedGroups = $sharedRoles = array();
        $mess = ConfService::getMessages();
        foreach($roles as $rId){
            $role = AuthService::getRole($rId);
            if ($role == null) continue;

            $RIGHT = $role->getAcl($repoId);
            if (empty($RIGHT)) continue;
            $ID = $rId;
            $WATCH = false;
            if(strpos($rId, "AJXP_USR_/") === 0){
                $userId = substr($rId, strlen('AJXP_USR_/'));
                $role = AuthService::getRole($rId);
                $userObject = ConfService::getConfStorageImpl()->createUserObject($userId);
                $LABEL = $role->filterParameterValue("core.conf", "USER_DISPLAY_NAME", AJXP_REPO_SCOPE_ALL, "");
                if(empty($LABEL)) $LABEL = $userId;
                $TYPE = $userObject->hasParent()?"tmp_user":"user";
                if ($this->watcher !== false && $currentFileUrl != null) {
                    $WATCH = $this->watcher->hasWatchOnNode(
                        new AJXP_Node($currentFileUrl),
                        $userId,
                        MetaWatchRegister::$META_WATCH_USERS_NAMESPACE
                    );
                }
                $ID = $userId;
            }else if($rId == "AJXP_GRP_/"){
                $rId = "AJXP_GRP_/";
                $TYPE = "group";
                $LABEL = $mess["447"];
            }else if(strpos($rId, "AJXP_GRP_/") === 0){
                if(empty($loadedGroups)){
                    $displayAll = ConfService::getCoreConf("CROSSUSERS_ALLGROUPS_DISPLAY", "conf");
                    if($displayAll){
                        AuthService::setGroupFiltering(false);
                    }
                    $loadedGroups = AuthService::listChildrenGroups();
                    if($displayAll){
                        AuthService::setGroupFiltering(true);
                    }else{
                        $baseGroup = AuthService::filterBaseGroup("/");
                        foreach($loadedGroups as $loadedG => $loadedLabel){
                            unset($loadedGroups[$loadedG]);
                            $loadedGroups[rtrim($baseGroup, "/")."/".ltrim($loadedG, "/")] = $loadedLabel;
                        }
                    }
                }
                $groupId = substr($rId, strlen('AJXP_GRP_'));
                if(isSet($loadedGroups[$groupId])) {
                    $LABEL = $loadedGroups[$groupId];
                }
                if($groupId == "/"){
                    $LABEL = $mess["447"];
                }
                if(empty($LABEL)) $LABEL = $groupId;
                $TYPE = "group";
            }else{
                $role = AuthService::getRole($rId);
                $LABEL = $role->getLabel();
                $TYPE = 'group';
            }

            if(empty($LABEL)) $LABEL = $rId;
            $entry = array(
                "ID"    => $ID,
                "TYPE"  => $TYPE,
                "LABEL" => $LABEL,
                "RIGHT" => $RIGHT
            );
            if($WATCH) $entry["WATCH"] = $WATCH;
            if($TYPE == "group"){
                $sharedGroups[$entry["ID"]] = $entry;
            } else {
                $sharedEntries[$entry["ID"]] = $entry;
            }
        }

        if (!$mixUsersAndGroups) {
            return array("USERS" => $sharedEntries, "GROUPS" => $sharedGroups);
        }else{
            return array_merge(array_values($sharedGroups), array_values($sharedEntries));

        }
    }

    /**
     * @param $httpVars
     * @param Repository $repository
     * @param AbstractAccessDriver $accessDriver
     * @return mixed An array containing the hash (0) and the generated url (1)
     * @throws Exception
     */
    public function createSharedMinisite($httpVars, $repository, $accessDriver)
    {
        $uniqueUser = null;
        if(isSet($httpVars["repository_id"]) && isSet($httpVars["guest_user_id"])){
            $existingData = $this->getShareStore()->loadShare($httpVars["hash"]);
            $existingU = "";
            if(isSet($existingData["PRELOG_USER"])) $existingU = $existingData["PRELOG_USER"];
            else if(isSet($existingData["PRESET_LOGIN"])) $existingU = $existingData["PRESET_LOGIN"];
            $uniqueUser = $httpVars["guest_user_id"];
            if(isset($httpVars["guest_user_pass"]) && strlen($httpVars["guest_user_pass"]) && $uniqueUser == $existingU){
                //$userPass = $httpVars["guest_user_pass"];
                // UPDATE GUEST USER PASS HERE
                AuthService::updatePassword($uniqueUser, $httpVars["guest_user_pass"]);
            }else if(isSet($httpVars["guest_user_pass"]) && $httpVars["guest_user_pass"] == ""){

            }else if(isSet($existingData["PRESET_LOGIN"])){
                $httpVars["KEEP_PRESET_LOGIN"] = true;
            }

        }else if (isSet($httpVars["create_guest_user"])) {
            // Create a guest user
            $userId = substr(md5(time()), 0, 12);
            $pref = $this->getFilteredOption("SHARED_USERS_TMP_PREFIX", $this->repository);
            if (!empty($pref)) {
                $userId = $pref.$userId;
            }
            if(!empty($httpVars["guest_user_pass"])){
                $userPass = $httpVars["guest_user_pass"];
            }else{
                $userPass = substr(md5(time()), 13, 24);
            }
            $uniqueUser = $userId;
        }
        if(isSet($uniqueUser)){
            if(isSet($userPass)) {
                $httpVars["user_pass_0"] = $httpVars["shared_pass"] = $userPass;
            }
            $httpVars["user_0"] = $uniqueUser;
            $httpVars["entry_type_0"] = "user";
            $httpVars["right_read_0"] = (isSet($httpVars["simple_right_read"]) ? "true" : "false");
            $httpVars["right_write_0"] = (isSet($httpVars["simple_right_write"]) ? "true" : "false");
            $httpVars["right_watch_0"] = "false";
            $httpVars["disable_download"] = (isSet($httpVars["simple_right_download"]) ? false : true);
            if ($httpVars["right_read_0"] == "false" && !$httpVars["disable_download"]) {
                $httpVars["right_read_0"] = "true";
            }
            if ($httpVars["right_write_0"] == "false" && $httpVars["right_read_0"] == "false") {
                return "share_center.58";
            }
        }

        $httpVars["minisite"] = true;
        $httpVars["selection"] = true;
        if(!isSet($userSelection)){
            $userSelection = new UserSelection($repository, $httpVars);
            $setFilter = false;
            if($userSelection->isUnique()){
                $node = $userSelection->getUniqueNode();
                $node->loadNodeInfo();
                if($node->isLeaf()){
                    $setFilter = true;
                    $httpVars["file"] = "/";
                    $httpVars["nodes"] = array("/");
                }
            }else{
                $setFilter = true;
            }
            $nodes = $userSelection->buildNodes();
            $hasDir = false; $hasFile = false;
            foreach($nodes as $n){
                $n->loadNodeInfo();
                if($n->isLeaf()) $hasFile = true;
                else $hasDir = true;
            }
            if( ( $hasDir && !$this->getAuthorization("folder", "minisite") ) || ($hasFile && !$this->getAuthorization("file"))){
                return 103;
            }
            if($setFilter){ // Either it's a file, or many nodes are shared
                $httpVars["filter_nodes"] = $nodes;
            }
            if(!isSet($httpVars["repo_label"])){
                $first = $userSelection->getUniqueNode();
                $httpVars["repo_label"] = SystemTextEncoding::toUTF8($first->getLabel());
            }
        }
        $newRepo = $this->createSharedRepository($httpVars, $repository, $accessDriver, $uniqueUser);

        if(!is_a($newRepo, "Repository")) return $newRepo;

        $newId = $newRepo->getId();

        $this->getPublicAccessManager()->initFolder();

        if(isset($existingData)){
            $repo = ConfService::getRepositoryById($existingData["REPOSITORY"]);
            if($repo == null) throw new Exception("Oups, something went wrong");
            $this->getShareStore()->testUserCanEditShare($repo->getOwner());
            $data = $existingData;
        }else{
            $data = array(
                "REPOSITORY"=>$newId
            );
        }
        if(isSet($data["PRELOG_USER"]))unset($data["PRELOG_USER"]);
        if(isSet($data["PRESET_LOGIN"]))unset($data["PRESET_LOGIN"]);
        if((isSet($httpVars["create_guest_user"]) && isSet($userId)) || (isSet($httpVars["guest_user_id"]))){
            if(!isset($userId)) $userId = $httpVars["guest_user_id"];
            if(empty($httpVars["guest_user_pass"]) && !isSet($httpVars["KEEP_PRESET_LOGIN"])){
                $data["PRELOG_USER"] = $userId;
            }else{
                $data["PRESET_LOGIN"] = $userId;
            }
        }
        $data["DOWNLOAD_DISABLED"] = $httpVars["disable_download"];
        $data["AJXP_APPLICATION_BASE"] = AJXP_Utils::detectServerURL(true);
        if(isSet($httpVars["minisite_layout"])){
            $data["AJXP_TEMPLATE_NAME"] = $httpVars["minisite_layout"];
        }
        if(isSet($httpVars["expiration"])){
            if(intval($httpVars["expiration"]) > 0){
                $data["EXPIRE_TIME"] = time() + intval($httpVars["expiration"]) * 86400;
            }else if(isSet($data["EXPIRE_TIME"])) {
                unset($data["EXPIRE_TIME"]);
            }
        }
        if(isSet($httpVars["downloadlimit"])){
            if(intval($httpVars["downloadlimit"]) > 0){
                $data["DOWNLOAD_LIMIT"] = intval($httpVars["downloadlimit"]);
            }else if(isSet($data["DOWNLOAD_LIMIT"])){
                unset($data["DOWNLOAD_LIMIT"]);
            }
        }
        if(AuthService::usersEnabled()){
            $data["OWNER_ID"] = AuthService::getLoggedUser()->getId();
        }

        if(!isSet($httpVars["repository_id"])){
            try{
                $forceHash = null;
                if(isSet($httpVars["custom_handle"]) && !empty($httpVars["custom_handle"])){
                    // Existing already
                    $value = AJXP_Utils::sanitize($httpVars["custom_handle"], AJXP_SANITIZE_ALPHANUM);
                    $value = strtolower($value);
                    $test = $this->getShareStore()->loadShare($value);
                    $mess = ConfService::getMessages();
                    if(!empty($test)) throw new Exception($mess["share_center.172"]);
                    $forceHash = $value;
                }
                $hash = $this->getShareStore()->storeShare($repository->getId(), $data, "minisite", $forceHash);
            }catch(Exception $e){
                return $e->getMessage();
            }
            $url = $this->getPublicAccessManager()->buildPublicLink($hash);
            $files = $userSelection->getFiles();
            $this->logInfo("New Share", array(
                "file" => "'".$httpVars['file']."'",
                "files" => $files,
                "url" => $url,
                "expiration" => $data['EXPIRE_TIME'],
                "limit" => $data['DOWNLOAD_LIMIT'],
                "repo_uuid" => $repository->uuid
            ));
            AJXP_Controller::applyHook("node.share.create", array(
                'type' => 'minisite',
                'repository' => &$repository,
                'accessDriver' => &$accessDriver,
                'data' => &$data,
                'url' => $url,
                'new_repository' => &$newRepo
            ));
        }else{
            try{
                $hash = $httpVars["hash"];
                $updateHash = null;
                if(isSet($httpVars["custom_handle"]) && !empty($httpVars["custom_handle"]) && $httpVars["custom_handle"] != $httpVars["hash"]){
                    // Existing already
                    $value = AJXP_Utils::sanitize($httpVars["custom_handle"], AJXP_SANITIZE_ALPHANUM);
                    $value = strtolower($value);
                    $test = $this->getShareStore()->loadShare($value);
                    if(!empty($test)) throw new Exception("Sorry hash already exists");
                    $updateHash = $value;
                }
                $hash = $this->getShareStore()->storeShare($repository->getId(), $data, "minisite", $hash, $updateHash);
            }catch(Exception $e){
                return $e->getMessage();
            }
            $url = $this->getPublicAccessManager()->buildPublicLink($hash);
            $this->logInfo("Update Share", array(
                "file" => "'".$httpVars['file']."'",
                "files" => "'".$httpVars['file']."'",
                "url" => $url,
                "expiration" => $data['EXPIRE_TIME'],
                "limit" => $data['DOWNLOAD_LIMIT'],
                "repo_uuid" => $repository->uuid
            ));
            AJXP_Controller::applyHook("node.share.update", array(
                'type' => 'minisite',
                'repository' => &$repository,
                'accessDriver' => &$accessDriver,
                'data' => &$data,
                'url' => $url,
                'new_repository' => &$newRepo
            ));
        }

        return array($hash, $url);
    }

    /**
     * @param array $httpVars
     * @param Repository $repository
     * @param AbstractAccessDriver $accessDriver
     * @param null $uniqueUser
     * @throws Exception
     * @return int|Repository
     */
    public function createSharedRepository($httpVars, $repository, $accessDriver, $uniqueUser = null)
    {
        // ERRORS
        // 100 : missing args
        // 101 : repository label already exists
        // 102 : user already exists
        // 103 : current user is not allowed to share
        // SUCCESS
        // 200

        if (!isSet($httpVars["repo_label"]) || $httpVars["repo_label"] == "") {
            return 100;
        }
        /*
        // FILE IS ALWAYS THE PARENT FOLDER SO WE NOW CHECK FOLDER_SHARING AT A HIGHER LEVEL
        $file = AJXP_Utils::decodeSecureMagic($httpVars["file"]);
        $foldersharing = $this->getFilteredOption("ENABLE_FOLDER_SHARING", $this->repository->getId());
        $foldersharingDisabled = isset($foldersharing) && ($foldersharing === false || (is_string($foldersharing) && $foldersharing == "disable"));
        if (is_dir($this->urlBase.$file) && $foldersharingDisabled) {
            return 103;
        }
        */
        $loggedUser = AuthService::getLoggedUser();
        $actRights = $loggedUser->mergedRole->listActionsStatesFor($repository);
        if (isSet($actRights["share"]) && $actRights["share"] === false) {
            return 103;
        }
        $users = array();
        $uRights = array();
        $uPasses = array();
        $groups = array();
        $uWatches = array();

        $index = 0;
        $prefix = $this->getFilteredOption("SHARED_USERS_TMP_PREFIX", $this->repository);
        while (isSet($httpVars["user_".$index])) {
            $eType = $httpVars["entry_type_".$index];
            $uWatch = false;
            $rightString = ($httpVars["right_read_".$index]=="true"?"r":"").($httpVars["right_write_".$index]=="true"?"w":"");
            if($this->watcher !== false) $uWatch = $httpVars["right_watch_".$index] == "true" ? true : false;
            if (empty($rightString)) {
                $index++;
                continue;
            }
            if ($eType == "user") {
                $u = AJXP_Utils::decodeSecureMagic($httpVars["user_".$index], AJXP_SANITIZE_EMAILCHARS);
                if (!AuthService::userExists($u) && !isSet($httpVars["user_pass_".$index])) {
                    $index++;
                    continue;
                } else if (AuthService::userExists($u, "w") && isSet($httpVars["user_pass_".$index])) {
                    throw new Exception("User $u already exists, please choose another name.");
                }
                if(!AuthService::userExists($u, "r") && !empty($prefix)
                && strpos($u, $prefix)!==0 ){
                    $u = $prefix . $u;
                }
                $users[] = $u;
            } else {
                $u = AJXP_Utils::decodeSecureMagic($httpVars["user_".$index]);
                if (strpos($u, "/AJXP_TEAM/") === 0) {
                    $confDriver = ConfService::getConfStorageImpl();
                    if (method_exists($confDriver, "teamIdToUsers")) {
                        $teamUsers = $confDriver->teamIdToUsers(str_replace("/AJXP_TEAM/", "", $u));
                        foreach ($teamUsers as $userId) {
                            $users[] = $userId;
                            $uRights[$userId] = $rightString;
                            if ($this->watcher !== false) {
                                $uWatches[$userId] = $uWatch;
                            }
                        }
                    }
                    $index++;
                    continue;
                } else {
                    $groups[] = $u;
                }
            }
            $uRights[$u] = $rightString;
            $uPasses[$u] = isSet($httpVars["user_pass_".$index])?$httpVars["user_pass_".$index]:"";
            if ($this->watcher !== false) {
                $uWatches[$u] = $uWatch;
            }
            $index ++;
        }

        $label = AJXP_Utils::sanitize(AJXP_Utils::securePath($httpVars["repo_label"]), AJXP_SANITIZE_HTML);
        $description = AJXP_Utils::sanitize(AJXP_Utils::securePath($httpVars["repo_description"]), AJXP_SANITIZE_HTML);
        if (isSet($httpVars["repository_id"])) {
            $editingRepo = ConfService::getRepositoryById($httpVars["repository_id"]);
        }

        // CHECK USER & REPO DOES NOT ALREADY EXISTS
        if ( $this->getFilteredOption("AVOID_SHARED_FOLDER_SAME_LABEL", $this->repository) == true) {
            $count = 0;
            $similarLabelRepos = ConfService::listRepositoriesWithCriteria(array("display" => $label), $count);
            if($count && !isSet($editingRepo)){
                return 101;
            }
            if($count && isSet($editingRepo)){
                foreach($similarLabelRepos as $slr){
                    if($slr->getUniqueId() != $editingRepo->getUniqueId()){
                        return 101;
                    }
                }
            }
            /*
            $repos = ConfService::getRepositoriesList();
            foreach ($repos as $obj) {
                if ($obj->getDisplay() == $label && (!isSet($editingRepo) || $editingRepo != $obj)) {
                }
            }
            */
        }

        $confDriver = ConfService::getConfStorageImpl();
        foreach ($users as $userName) {
            if (AuthService::userExists($userName)) {
                // check that it's a child user
                $userObject = $confDriver->createUserObject($userName);
                if ( ConfService::getCoreConf("ALLOW_CROSSUSERS_SHARING", "conf") != true && ( !$userObject->hasParent() || $userObject->getParent() != $loggedUser->id ) ) {
                    return 102;
                }
            } else {
                if ( ($httpVars["create_guest_user"] != "true" && !ConfService::getCoreConf("USER_CREATE_USERS", "conf")) || AuthService::isReservedUserId($userName)) {
                    return 102;
                }
                if (!isSet($httpVars["shared_pass"]) || $httpVars["shared_pass"] == "") {
                    return 100;
                }
            }
        }

        // CREATE SHARED OPTIONS
        $options = $accessDriver->makeSharedRepositoryOptions($httpVars, $repository);
        $customData = array();
        foreach ($httpVars as $key => $value) {
            if (substr($key, 0, strlen("PLUGINS_DATA_")) == "PLUGINS_DATA_") {
                $customData[substr($key, strlen("PLUGINS_DATA_"))] = $value;
            }
        }
        if (count($customData)) {
            $options["PLUGINS_DATA"] = $customData;
        }
        if (isSet($editingRepo)) {
            $this->getShareStore()->testUserCanEditShare($editingRepo->getOwner());
            $newRepo = $editingRepo;
            $replace = false;
            if ($editingRepo->getDisplay() != $label) {
                $newRepo->setDisplay($label);
                $replace= true;
            }
            if($editingRepo->getDescription() != $description){
                $newRepo->setDescription($description);
                $replace = true;
            }
            if($replace) ConfService::replaceRepository($httpVars["repository_id"], $newRepo);
        } else {
            if ($repository->getOption("META_SOURCES")) {
                $options["META_SOURCES"] = $repository->getOption("META_SOURCES");
                foreach ($options["META_SOURCES"] as $index => &$data) {
                    if (isSet($data["USE_SESSION_CREDENTIALS"]) && $data["USE_SESSION_CREDENTIALS"] === true) {
                        $options["META_SOURCES"][$index]["ENCODED_CREDENTIALS"] = AJXP_Safe::getEncodedCredentialString();
                    }
                    if($index == "meta.syncable" && (!isSet($data["REPO_SYNCABLE"]) || $data["REPO_SYNCABLE"] === true )){
                        $data["REQUIRES_INDEXATION"] = true;
                    }
                }
            }
            $newRepo = $repository->createSharedChild(
                $label,
                $options,
                $repository->id,
                $loggedUser->id,
                null
            );
            $gPath = $loggedUser->getGroupPath();
            if (!empty($gPath) && !ConfService::getCoreConf("CROSSUSERS_ALLGROUPS", "conf")) {
                $newRepo->setGroupPath($gPath);
            }
            $newRepo->setDescription($description);
			$newRepo->options["PATH"] = SystemTextEncoding::fromStorageEncoding($newRepo->options["PATH"]);
            if(isSet($httpVars["filter_nodes"])){
                $newRepo->setContentFilter(new ContentFilter($httpVars["filter_nodes"]));
            }
            ConfService::addRepository($newRepo);
            if(!isSet($httpVars["minisite"])){
                $this->getShareStore()->storeShare($repository->getId(), array(
                    "REPOSITORY" => $newRepo->getUniqueId(),
                    "OWNER_ID" => $loggedUser->getId()), "repository");
            }
        }

        $sel = new UserSelection($this->repository, $httpVars);
        $file = $sel->getUniqueFile();
        $newRepoUniqueId = $newRepo->getUniqueId();

        if (isSet($editingRepo)) {

            $currentRights = $this->computeSharedRepositoryAccessRights($httpVars["repository_id"], false, $this->urlBase.$file);
            $originalUsers = array_keys($currentRights["USERS"]);
            $removeUsers = array_diff($originalUsers, $users);
            if (count($removeUsers)) {
                foreach ($removeUsers as $user) {
                    if (AuthService::userExists($user)) {
                        $userObject = $confDriver->createUserObject($user);
                        $userObject->personalRole->setAcl($newRepoUniqueId, "");
                        $userObject->save("superuser");
                    }
                    if($this->watcher !== false){
                        $this->watcher->removeWatchFromFolder(
                            new AJXP_Node($this->urlBase.$file),
                            $user,
                            true
                        );
                    }
                }
            }
            $originalGroups = array_keys($currentRights["GROUPS"]);
            $removeGroups = array_diff($originalGroups, $groups);
            if (count($removeGroups)) {
                foreach ($removeGroups as $groupId) {
                    $role = AuthService::getRole($groupId);
                    if ($role !== false) {
                        $role->setAcl($newRepoUniqueId, "");
                        AuthService::updateRole($role);
                    }
                }
            }
        }

        foreach ($users as $userName) {
            if (AuthService::userExists($userName, "r")) {
                // check that it's a child user
                $userObject = $confDriver->createUserObject($userName);
            } else {
                if (ConfService::getAuthDriverImpl()->getOptionAsBool("TRANSMIT_CLEAR_PASS")) {
                    $pass = $uPasses[$userName];
                } else {
                    $pass = md5($uPasses[$userName]);
                }
                if(!isSet($httpVars["minisite"])){
                    // This is an explicit user creation - check possible limits
                    AJXP_Controller::applyHook("user.before_create", array($userName, null, false, false));
                    $limit = $loggedUser->mergedRole->filterParameterValue("core.conf", "USER_SHARED_USERS_LIMIT", AJXP_REPO_SCOPE_ALL, "");
                    if (!empty($limit) && intval($limit) > 0) {
                        $count = count(ConfService::getConfStorageImpl()->getUserChildren($loggedUser->getId()));
                        if ($count >= $limit) {
                            $mess = ConfService::getMessages();
                            throw new Exception($mess['483']);
                        }
                    }
                }
                AuthService::createUser($userName, $pass, false, isSet($httpVars["minisite"]));
                $userObject = $confDriver->createUserObject($userName);
                $userObject->personalRole->clearAcls();
                $userObject->setParent($loggedUser->id);
                $userObject->setGroupPath($loggedUser->getGroupPath());
                $userObject->setProfile("shared");
                if(isSet($httpVars["minisite"])){
                    $mess = ConfService::getMessages();
                    $userObject->setHidden(true);
                    $userObject->personalRole->setParameterValue("core.conf", "USER_DISPLAY_NAME", "[".$mess["share_center.109"]."] ". AJXP_Utils::sanitize($newRepo->getDisplay(), AJXP_SANITIZE_EMAILCHARS));
                }
                AJXP_Controller::applyHook("user.after_create", array($userObject));
            }
            // CREATE USER WITH NEW REPO RIGHTS
            $userObject->personalRole->setAcl($newRepoUniqueId, $uRights[$userName]);
            // FORK MASK IF THERE IS ANY
            if($file != "/" && $loggedUser->mergedRole->hasMask($repository->getId())){
                $parentTree = $loggedUser->mergedRole->getMask($repository->getId())->getTree();
                // Try to find a branch on the current selection
                $parts = explode("/", trim($file, "/"));
                while( ($next = array_shift($parts))  !== null){
                    if(isSet($parentTree[$next])) {
                        $parentTree = $parentTree[$next];
                    }else{
                        $parentTree = null;
                        break;
                    }
                }
                if($parentTree != null){
                    $newMask = new AJXP_PermissionMask();
                    $newMask->updateTree($parentTree);
                }
                if(isset($newMask)){
                    $userObject->personalRole->setMask($newRepoUniqueId, $newMask);
                }
            }

            if (isSet($httpVars["minisite"])) {
                if(isset($editingRepo)){
                    try{
                        AuthService::deleteRole("AJXP_SHARED-".$newRepoUniqueId);
                    }catch (Exception $e){}
                }
                $newRole = new AJXP_Role("AJXP_SHARED-".$newRepoUniqueId);
                $r = AuthService::getRole("MINISITE");
                if (is_a($r, "AJXP_Role")) {
                    if ($httpVars["disable_download"]) {
                        $f = AuthService::getRole("MINISITE_NODOWNLOAD");
                        if (is_a($f, "AJXP_Role")) {
                            $r = $f->override($r);
                        }
                    }
                    $allData = $r->getDataArray();
                    $newData = $newRole->getDataArray();
                    if(isSet($allData["ACTIONS"][AJXP_REPO_SCOPE_SHARED])) $newData["ACTIONS"][$newRepoUniqueId] = $allData["ACTIONS"][AJXP_REPO_SCOPE_SHARED];
                    if(isSet($allData["PARAMETERS"][AJXP_REPO_SCOPE_SHARED])) $newData["PARAMETERS"][$newRepoUniqueId] = $allData["PARAMETERS"][AJXP_REPO_SCOPE_SHARED];
                    $newRole->bunchUpdate($newData);
                    AuthService::updateRole($newRole);
                    $userObject->addRole($newRole);
                }
            }
            $userObject->save("superuser");
            if ($this->watcher !== false) {
                // Register a watch on the current folder for shared user
                if ($uWatches[$userName]) {
                    $this->watcher->setWatchOnFolder(
                        new AJXP_Node("pydio://".$newRepoUniqueId."/"),
                        $userName,
                        MetaWatchRegister::$META_WATCH_USERS_CHANGE,
                        array(AuthService::getLoggedUser()->getId())
                    );
                } else {
                    $this->watcher->removeWatchFromFolder(
                        new AJXP_Node("pydio://".$newRepoUniqueId."/"),
                        $userName,
                        true
                    );
                }
            }
        }

        if ($this->watcher !== false) {
            // Register a watch on the new repository root for current user
            if ($httpVars["self_watch_folder"] == "true") {
                $this->watcher->setWatchOnFolder(
                    new AJXP_Node("pydio://".$newRepoUniqueId."/"),
                    AuthService::getLoggedUser()->getId(),
                    MetaWatchRegister::$META_WATCH_BOTH);
            } else {
                $this->watcher->removeWatchFromFolder(
                    new AJXP_Node("pydio://".$newRepoUniqueId."/"),
                    AuthService::getLoggedUser()->getId());
            }
        }

        foreach ($groups as $group) {
            $r = $uRights[$group];
            /*if($group == "AJXP_GRP_/") {
                $group = "ROOT_ROLE";
            }*/
            $grRole = AuthService::getRole($group, true);
            $grRole->setAcl($newRepoUniqueId, $r);
            AuthService::updateRole($grRole);
        }

        if (array_key_exists("minisite", $httpVars) && $httpVars["minisite"] != true) {
            AJXP_Controller::applyHook( (isSet($editingRepo) ? "node.share.update" : "node.share.create"), array(
                'type' => 'repository',
                'repository' => &$repository,
                'accessDriver' => &$accessDriver,
                'new_repository' => &$newRepo
            ));
        }

        return $newRepo;
    }


    /**************************/
    /* LISTING FUNCTIONS
    /**************************/
    /**
     * @param bool|string $currentUser if true, currently logged user. if false all users. If string, user ID.
     * @param string $parentRepositoryId
     * @param null $cursor
     * @return array
     */
    public function listShares($currentUser = true, $parentRepositoryId="", $cursor = null){
        if($currentUser === false){
            $crtUser = "";
        }else if(AuthService::usersEnabled()){
            if($currentUser === true){
                $crtUser = AuthService::getLoggedUser()->getId();
            }else{
                $crtUser = $currentUser;
            }
        }else{
            $crtUser = "shared";
        }
        return $this->getShareStore()->listShares($crtUser, $parentRepositoryId, $cursor);
    }

    /**
     * @param $rootPath
     * @param bool|string $currentUser if true, currently logged user. if false all users. If string, user ID.
     * @param string $parentRepositoryId
     * @param null $cursor
     * @param bool $xmlPrint
     * @return AJXP_Node[]
     */
    public function listSharesAsNodes($rootPath, $currentUser = true, $parentRepositoryId = "", $cursor = null, $xmlPrint = false){

        $shares =  $this->listShares($currentUser, $parentRepositoryId, $cursor);
        $nodes = array();
        $parent = ConfService::getRepositoryById($parentRepositoryId);

        foreach($shares as $hash => $shareData){

            $icon = "hdd_external_mount.png";
            $meta = array(
                "icon"			=> $icon,
                "openicon"		=> $icon,
                "ajxp_mime" 	=> "repository_editable"
            );

            $shareType = $shareData["SHARE_TYPE"];
            $meta["share_type"] = $shareType;
            $meta["ajxp_shared"] = true;

            if(!is_object($shareData["REPOSITORY"])){

                $repoId = $shareData["REPOSITORY"];
                $repoObject = ConfService::getRepositoryById($repoId);
                if($repoObject == null){
                    $meta["text"] = "Invalid link";
                    continue;
                }
                $meta["text"] = $repoObject->getDisplay();
                $meta["share_type_readable"] =  $repoObject->hasContentFilter() ? "Publiclet" : ($shareType == "repository"? "Workspace": "Minisite");
                if(isSet($shareData["LEGACY_REPO_OR_MINI"])){
                    $meta["share_type_readable"] = "Repository or Minisite (legacy)";
                }
                $meta["share_data"] = ($shareType == "repository" ? 'Shared as workspace: '.$repoObject->getDisplay() : $this->getPublicAccessManager()->buildPublicLink($hash));
                $meta["shared_element_hash"] = $hash;
                $meta["owner"] = $repoObject->getOwner();
                if($shareType != "repository") {
                    $meta["copy_url"]  = $this->getPublicAccessManager()->buildPublicLink($hash);
                }
                $meta["shared_element_parent_repository"] = $repoObject->getParentId();
                if(!empty($parent)) {
                    $parentPath = $parent->getOption("PATH", false, $meta["owner"]);
                    $meta["shared_element_parent_repository_label"] = $parent->getDisplay();
                }else{
                    $crtParent = ConfService::getRepositoryById($repoObject->getParentId());
                    if(!empty($crtParent)){
                        $meta["shared_element_parent_repository_label"] = $crtParent->getDisplay();
                    }else {
                        $meta["shared_element_parent_repository_label"] = $repoObject->getParentId();
                    }
                }
                if($shareType != "repository"){
                    if($repoObject->hasContentFilter()){
                        $meta["ajxp_shared_minisite"] = "file";
                        $meta["icon"] = "mime_empty.png";
                        $meta["original_path"] = array_pop(array_keys($repoObject->getContentFilter()->filters));
                    }else{
                        $meta["ajxp_shared_minisite"] = "public";
                        $meta["icon"] = "folder.png";
                        $meta["original_path"] = $repoObject->getOption("PATH");
                    }
                    $meta["icon"] = $repoObject->hasContentFilter() ? "mime_empty.png" : "folder.png";
                }else{
                    $meta["original_path"] = $repoObject->getOption("PATH");
                }
                if(!empty($parentPath) &&  strpos($meta["original_path"], $parentPath) === 0){
                    $meta["original_path"] = substr($meta["original_path"], strlen($parentPath));
                }

            }else if(is_a($shareData["REPOSITORY"], "Repository") && !empty($shareData["FILE_PATH"])){

                $meta["owner"] = $shareData["OWNER_ID"];
                $meta["share_type_readable"] = "Publiclet (legacy)";
                $meta["text"] = basename($shareData["FILE_PATH"]);
                $meta["icon"] = "mime_empty.png";
                $meta["share_data"] = $meta["copy_url"] = $this->getPublicAccessManager()->buildPublicLink($hash);
                $meta["share_link"] = true;
                $meta["shared_element_hash"] = $hash;
                $meta["ajxp_shared_publiclet"] = $hash;

            }else{

                continue;

            }

            if($xmlPrint){
                AJXP_XMLWriter::renderAjxpNode(new AJXP_Node($rootPath."/".$hash, $meta));
            }else{
                $nodes[] = new AJXP_Node($rootPath."/".$hash, $meta);
            }
        }

        return $nodes;


    }

    /**
     * @param String $shareId
     * @param array $shareData
     * @param AJXP_Node $node
     * @throws Exception
     * @return array|bool
     */
    public function shareToJson($shareId, $shareData, $node = null){

        $messages = ConfService::getMessages();
        $jsonData = array();
        $elementWatch = false;
        if($shareData["type"] == "file"){

            $pData = $this->getShareStore()->loadShare($shareId);
            if (!count($pData)) {
                return false;
            }
            foreach($this->getShareStore()->modifiableShareKeys as $key){
                if(isSet($pData[$key])) $shareData[$key] = $pData[$key];
            }
            if ($pData["OWNER_ID"] != AuthService::getLoggedUser()->getId() && !AuthService::getLoggedUser()->isAdmin()) {
                throw new Exception($messages["share_center.48"]);
            }
            if (isSet($shareData["short_form_url"])) {
                $link = $shareData["short_form_url"];
            } else {
                $link = $this->getPublicAccessManager()->buildPublicLink($shareId);
            }
            if ($this->watcher != false && $node != null) {
                $result = array();
                $elementWatch = $this->watcher->hasWatchOnNode(
                    $node,
                    AuthService::getLoggedUser()->getId(),
                    MetaWatchRegister::$META_WATCH_USERS_NAMESPACE,
                    $result
                );
                if ($elementWatch && !in_array($shareId, $result)) {
                    $elementWatch = false;
                }
            }
            $jsonData = array_merge(array(
                "element_id"       => $shareId,
                "publiclet_link"   => $link,
                "download_counter" => $this->getShareStore()->getCurrentDownloadCounter($shareId),
                "download_limit"   => $pData["DOWNLOAD_LIMIT"],
                "expire_time"      => ($pData["EXPIRE_TIME"]!=0?date($messages["date_format"], $pData["EXPIRE_TIME"]):0),
                "has_password"     => (!empty($pData["PASSWORD"])),
                "element_watch"    => $elementWatch,
                "is_expired"       => $this->getShareStore()->isShareExpired($shareId, $pData)
            ), $shareData);


        }else if($shareData["type"] == "minisite" || $shareData["type"] == "repository"){

            $repoId = $shareId;
            if(strpos($repoId, "repo-") === 0){
                // Legacy
                $repoId = str_replace("repo-", "", $repoId);
                $shareData["type"] = "repository";
            }
            $minisite = ($shareData["type"] == "minisite");
            $minisiteIsPublic = false;
            $dlDisabled = false;
            $minisiteLink = '';
            if ($minisite) {
                $minisiteData = $this->getShareStore()->loadShare($shareId);
                $repoId = $minisiteData["REPOSITORY"];
                $minisiteIsPublic = isSet($minisiteData["PRELOG_USER"]);
                $dlDisabled = isSet($minisiteData["DOWNLOAD_DISABLED"]) && $minisiteData["DOWNLOAD_DISABLED"] === true;
                if (isSet($shareData["short_form_url"])) {
                    $minisiteLink = $shareData["short_form_url"];
                } else {
                    $minisiteLink = $this->getPublicAccessManager()->buildPublicLink($shareId);
                }

            }
            $notExistsData = array(
                "error"         => true,
                "repositoryId"  => $repoId,
                "users_number"  => 0,
                "label"         => "Error - Cannot find shared data",
                "description"   => "Cannot find repository",
                "entries"       => array(),
                "element_watch" => false,
                "repository_url"=> ""
            );

            $repo = ConfService::getRepositoryById($repoId);
            if($repoId == null || ($repo == null && $node != null)){
                if($minisite){
                    $this->getShareStore()->getMetaManager()->removeShareFromMeta($node, $shareId);
                }
                return $notExistsData;
            } else if (!AuthService::getLoggedUser()->isAdmin() && $repo->getOwner() != AuthService::getLoggedUser()->getId()) {
                return $notExistsData;
            }
            if ($this->watcher != false && $node != null) {
                $elementWatch = $this->watcher->hasWatchOnNode(
                    new AJXP_Node("pydio://".$repoId."/"),
                    AuthService::getLoggedUser()->getId(),
                    MetaWatchRegister::$META_WATCH_NAMESPACE
                );
            }
            if($node != null){
                $sharedEntries = $this->computeSharedRepositoryAccessRights($repoId, true, "pydio://".$repoId."/");
            }else{
                $sharedEntries = $this->computeSharedRepositoryAccessRights($repoId, true, null);
            }

            $cFilter = $repo->getContentFilter();
            if(!empty($cFilter)){
                $cFilter = $cFilter->toArray();
            }
            $jsonData = array(
                "repositoryId"  => $repoId,
                "users_number"  => AuthService::countUsersForRepository($repoId),
                "label"         => $repo->getDisplay(),
                "description"   => $repo->getDescription(),
                "entries"       => $sharedEntries,
                "element_watch" => $elementWatch,
                "repository_url"=> AJXP_Utils::getWorkspaceShortcutURL($repo)."/",
                "content_filter"=> $cFilter
            );
            if (isSet($minisiteData)) {
                if(!empty($minisiteData["DOWNLOAD_LIMIT"]) && !$dlDisabled){
                    $jsonData["download_counter"] = $this->getShareStore()->getCurrentDownloadCounter($shareId);
                    $jsonData["download_limit"] = $minisiteData["DOWNLOAD_LIMIT"];
                }
                if(!empty($minisiteData["EXPIRE_TIME"])){
                    $delta = $minisiteData["EXPIRE_TIME"] - time();
                    $days = round($delta / (60*60*24));
                    $jsonData["expire_time"] = date($messages["date_format"], $minisiteData["EXPIRE_TIME"]);
                    $jsonData["expire_after"] = $days;
                }else{
                    $jsonData["expire_after"] = 0;
                }
                $jsonData["is_expired"] = $this->getShareStore()->isShareExpired($shareId, $minisiteData);
                if(isSet($minisiteData["AJXP_TEMPLATE_NAME"])){
                    $jsonData["minisite_layout"] = $minisiteData["AJXP_TEMPLATE_NAME"];
                }
                if(!$minisiteIsPublic){
                    $jsonData["has_password"] = true;
                }
                $jsonData["minisite"] = array(
                    "public"            => $minisiteIsPublic?"true":"false",
                    "public_link"       => $minisiteLink,
                    "disable_download"  => $dlDisabled,
                    "hash"              => $shareId,
                    "hash_is_shorten"   => isSet($shareData["short_form_url"])
                );
                foreach($this->getShareStore()->modifiableShareKeys as $key){
                    if(isSet($minisiteData[$key])) $jsonData[$key] = $minisiteData[$key];
                }

            }

        }


        return $jsonData;

    }


}
