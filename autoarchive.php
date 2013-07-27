<?php
/**
 * @version     $Id: autoarchive2.php 38 2009-12-10 11:15:43Z dhuelsmann $
 * @package     autoarchive2.php for Joomla 1.5.x
 * @copyright   Copyright (C) 2009 David Huelsmann. All rights reserved.
 * @license     GNU/GPL version 3.
 * Please note that this code is released subject to copyright
 * and is licensed to you under the terms of the GPL version 3.
 * http://www.gnu.org/licenses/gpl.html
 * No warranty is implied or offered.
 *
 * Although the GPL grants generous rights to you, it does require
 * you to observe certain limitations.  Please study the GPL
 * if you need details.
 *
 * For support and other information, visit http://www.huelsmann.us
 * To contact David Huelsmann, write to webmaster@huelsmann.us
 *
 * The original autoarchive mambot was developed by Tudor Ilisoi Copyright (C) 2006 TeachMeJoomla
 * for Joomla 1.0.x
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 *
 * This version has been converted to work on Joomla 1.5 native.
 * Thanks to Sajid Muhaimin Choudhury for providing code for move to section/catgory and republish 
 */

// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

JApplication::registerEvent( 'onPrepareContent', 'plgautoarchive' );

function plgautoarchive( $published, &$row, $params, $page=0 ) {
global  $debug;

    $debug = false; // Set to true to turn on debug output
 
 	if (!$published) return;
 	
  	// define language
	JPlugin::loadLanguage('plg_content_autoarchive2', JPATH_ADMINISTRATOR);

	// load plugin params info
	$db =& JFactory::getDBO();
	$query = "SELECT id FROM #__plugins WHERE element = 'autoarchive2' AND folder = 'content'";
	$db->setQuery( $query );
	$id = $db->loadResult();
	 	if ($db->getErrorNum()) {
	 		JError::raiseError( 500, $db->stderr() );
			return false;
		} //end if getErrorNum
    
    //debug
    if($debug)
        print_r ($db);
   
	$plugin =& JPluginHelper::getPlugin( 'content', 'autoarchive2' );
	$botparams = new JParameter( $plugin->params );
	
	$option = JRequest::getVar('option');
	$task = JRequest::getVar('task');

	$lifetime=$botparams->def('lifetime',15);
    $filename = JPATH_CACHE.DS.'timer.txt';
	//only run when viewing items, this prevents multiple executions on blog display and print/email popups
	if(  ($option=="com_content")&&( ($task=="view")||($lifetime>0) )  )	{
       if(!file_exists  ( $filename  )) {
            touch($filename);
            return; //interval not yet expired
        } // end if file_exists
        else    {
           clearstatcache();
           if( @filemtime($filename) < (time()- $lifetime) )    {
                @unlink($filename);
                plgaaexecute($row,$params, $botparams);
                return true; //exit, because code-based execution is not yet implemented}
            } // end if filemtime
        } // end else
    } // end if option
    
    return true;
      
} // end function plgautoarchive


/**
executes action on content items
*/

function plgaaexecute(&$itemrow, &$itemparams, &$params)	{
global $debug;


    $db =& JFactory::getDBO();
    $user = &JFactory::getUser();
    $userOffset = $user->getParam('timezone');

    $conf =& JFactory::getConfig();
    $mailfrom = $conf->getValue('config.mailfrom');
    $fromname = $conf->getValue('config.fromname');

	//Debug Statement
	if($debug) {
        print_r($params);
        print_r ($db);
        }

	$act=$params->def( 'action','' );
	$fixnotauth=$params->def( 'fixnotauth',1 );
	$notpub=$params->def( 'unpublished',1 );
	$notcat=$params->def( 'uncategorized',0 );
	$day=$params->def('days',0);
	$fpflag=$params->def('fpflag',0);

	if ($act) { //take action if set	
		$timenow = date( "Y-m-d H:i:s", time()+$userOffset*60*60 );
		$timefuture = strtotime(date("Y-m-d H:i:s", strtotime($timenow)) . "+" . $day . "day");
		$futuretime = date("Y-m-d H:i:s",$timefuture);
		$qlimit = $params->def( 'limit','1' );
		$where = Array();
		//sect filter
		$sf = 	trim($params->def( 'sectfilter','' ));
		//cat filter
		$cf = 	trim($params->def( 'catfilter','' ));
		if($notpub == 0)
			$where[] = " state >= 0 ";
			else
			$where[] = " (state = 1 OR state = 0) ";
			// Check to make sure not filtering by sectionid or catid
		if(!$cf && !$sf)	{ // ensure not filtering by section or category before including uncategorized or not
			if($notcat == 0)
				$where[] = " (catid > 0 AND sectionid > 0) ";
			else
				$where[] = " (catid >= 0 AND sectionid >= 0) ";
		}
		$where[] = "(publish_down <> '0000-00-00 00:00:00' AND publish_down <= '$timenow'  )";
		if ($sf) $where[]=' sectionid IN ('.$sf.')';
		if ($cf) $where[] = ' catid IN ('.$cf.') ';

		$adminemail = @trim($params->def( 'adminemail',$mosConfig_mailfrom ));

		$adminemail = explode(';',$adminemail);

		switch($act)	{
			case 'archive':
				$sql="UPDATE #__content   SET state=-1 , modified = " . $db->Quote( date( 'Y-m-d H:i:s' ) ) ;
				if ($fixnotauth) $sql.= ", publish_down='0000-00-00 00:00:00' " ;
			break;

			case 'trash':
				$sql="UPDATE #__content  SET state=-2 , modified = " . $db->Quote( date( 'Y-m-d H:i:s' ) );
				break;

			case 'delete':
				$sql="DELETE FROM #__content   ";
				break;

      /* Sajid Code begins */
			case 'add-to-cat':
      		$addtocat = $params->def( 'category','' );
  				if(!$addtocat) // can't perform - no section or category selected
					return JError::raiseError(500, JText::sprintf('ADD_TO_CAT_ERROR'));
          		$sql = "SELECT section FROM #__categories WHERE id = $addtocat ";
		        $db->setQuery( $sql );
		        $db->query();
		        $row=$db->loadResult();
         		$addtosec = $row;
				if($debug)
					echo "category/section $addtocat / $addtosec";
				$sql="UPDATE #__content   SET state=1, catid=" . $addtocat  .
				", sectionid=" . $addtosec  . ", modified = " . $db->Quote( date( 'Y-m-d H:i:s' ) );
				if($day) {
					$sql.= ", publish_down =" . $db->Quote($futuretime) ;
				}	elseif	($fixnotauth)	{
						$sql.= ", publish_down='0000-00-00 00:00:00' " ;
				}
				if($debug)
					echo $sql;
				break;
      /*Sajid Code ends */
				
			case 'move-to-cat':
         		$movetocat = $params->def( 'category','' );
				if(!$movetocat) // can't perform - no section or category selected
					return JError::raiseError(500,  JText::sprintf('MOVE_TO_CAT_ERROR')); 		
         		$sql = "SELECT section FROM #__categories WHERE id = $movetocat ";
		        $db->setQuery( $sql );
		        $db->query();
		        $row=$db->loadResult();
         		$movetosec = $row;
				if($debug)
					echo "category/section $movetocat / $movetosec";
				$sql="UPDATE #__content   SET state=-1, catid=" . $movetocat  . 
				", sectionid=" . $movetosec  . ", modified = " . $db->Quote( date( 'Y-m-d H:i:s' ) ) ;
				if ($fixnotauth) 
					$sql.= ", publish_down='0000-00-00 00:00:00' " ;
				if($debug)
					echo $sql;
					break;				

			default:
				//die("Autoexpire action is not set");
				exit();
			break;
		} //end switch

		//process items and send email before taking action
		/*
		//debug:determine total number of items
		$sql2="SELECT COUNT(id) FROM #__content" . ( count( $where ) ? "\n WHERE " . implode( ' AND ', $where ) : "");

		$database->setQuery($sql2);
		$database->query();

		$totalrows=$database->LoadResult();

		echo "<p>Total $totalrows rows</p>"	;
		*/

		$query=
			"SELECT * from #__content"
			. ( count( $where ) ? "\n WHERE " . implode( ' AND ', $where ) : "")

			."\n ORDER BY publish_down ASC"
			. "\n LIMIT $qlimit"
			;
		$db->setQuery($query);
		$db->query();
		$rows=$db->loadObjectList();
		if ($db->getErrorNum()) {
			JError::raiseError( 500, $db->stderr() );
			return false;
		} // end if $database
		
        //Debug Statement
		if($debug)
            echo "<pre>$query</pre>";

		$mailalert=$params->def( 'mailalert','none' );

		$ids=array(); //used to collect ids for update procedure

		foreach ($rows as $row)	{
			
            //Debug statement
			if($debug)
                echo "<h3>$act: ".$row->title." expired on: ".JHTML::_('date',$row->publish_down, JText::_('DATE_FORMAT_LC2') ).
                " section: ".$row->sectionid ." category: ".$row->catid . "id: ".$row->id ."</h3>";

			//send mail if configured to do so
			$subject= JText::_('ACTION_TAKEN');
			$subject.= $act;
			$subject.= JText::_('FOR');
			$subject.= "$row->title";
			$message= JText::_('AUTOMATIC_ACTION');
			$message.="\r\n\r\n $row->title";
			$message.="\r\n\r\n";
			$message.= JText::_('ACTION_TAKEN');
			$message.= "$act";
			$message.="\r\n\r\n";
			$message.= JText::_('MESSAGE_GENERATED');
			$mode=0; //plain text email

			$mailaddy=array();

			switch($mailalert)	{
  				case 'none':
  					break;

  				case 'both':
  					$mailaddy = $mailaddy + $adminemail; //get admin mail
 				 	$sql3="SELECT email from #__users"
 				 	." WHERE id=$row->created_by"
 					// ." AND usertype<>'Super Administrator'" //exclude admins
 					;
  					$db->setQuery($sql3);
 					$db->query();

					if ($db->getErrorNum()) {
						JError::raiseError( 500, $db->stderr() );
						return false;
					} //end if $database
					if (trim($authormail=$db->LoadResult())) $mailaddy[]=$authormail;
					break;

  				case 'author':
  					$sql3="SELECT email from #__users WHERE id=$row->created_by";
  					$db->setQuery($sql3);
 					$db->query();

					if ($db->getErrorNum()) {
						JError::raiseError( 500, $db->stderr() );
						return false;
					} // end if $database
					if (trim($authormail=$db->LoadResult())) $mailaddy[]=$authormail;
					break;

  				case 'admins':
 					$mailaddy = $mailaddy + $adminemail;
  					break;

  				default:
  					break;
			} // end switch

			$mailaddy=array_unique($mailaddy);

			//debug
			if($debug)
                print_r($mailaddy);

			/****
			unset($mailaddy);
			$mailaddy[]=$adminemail;
			****/

			if ($mailalert!='none')
				JUtility::sendMail( $mailfrom, $fromname, $mailaddy, $subject, $message, $mode );

			$ids[]=$row->id; //collect id for actual action :P

		} // end foreach

		//add ids to where clauses

		$idlimit="id IN (" . implode(",",$ids) . ")";
		$idstring = implode(',' , $ids);

		//apply action on items
		$query=$sql
		."\n WHERE ". $idlimit;
		;
        //debug
        if($debug)
            echo "<pre>$query</pre>";

		if(count($ids)) {//did we collect something?
			$db->setQuery($query);
			$db->query();

			if ($db->getErrorNum()) {
				JError::raiseError( 500, $db->stderr() );
				return false;
			} // end if database
		// Frontpage flag handling 		
		if($fpflag && $act != 'delete')	{
			$query5 = "DELETE FROM #__content_frontpage WHERE content_id IN($idstring)";
			$db->setQuery($query5);
			$db->Query();
			
			if ($db->getErrorNum()) {
				JError::raiseError( 500, $db->stderr() );
				return false;
			} // end if database
		}//end if $fpflag
		} // end if count
	} // end if $act
} // end function plgaaexecute
?>
