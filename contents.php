<?php
session_start();
if ($_SESSION['userLevel'] == 0) {
	die("Sorry, you need to be logged in to use ICErepo");
}

function strClean($var) {
	// returns converted entities where there are HTML entity equivalents
	return htmlentities($var, ENT_QUOTES, "UTF-8");
}

function numClean($var) {
	// returns a number, whole or decimal or null
	return is_numeric($var) ? floatval($var) : false;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>ICErepo v<?php echo $version;?></title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<script src="lib/base64.js"></script>
<script src="lib/github.js"></script>
<script src="lib/difflib.js"></script>
<script src="ice-repo.js"></script>
<link rel="stylesheet" type="text/css" href="ice-repo.css">
</head>

<body>
	
<?php
// Function to sort given values alphabetically
function alphasort($a, $b) {
	return strcmp($a->getPathname(), $b->getPathname());
}

// Class to put forward the values for sorting
class SortingIterator implements IteratorAggregate {
	private $iterator = null;
	public function __construct(Traversable $iterator, $callback) {
		$array = iterator_to_array($iterator);
		usort($array, $callback);
		$this->iterator = new ArrayIterator($array);
	}
	public function getIterator() {
	return $this->iterator;
	}
}

// Get a full list of dirs & files and begin sorting using above class & function
$repoPath = explode("@",strClean($_POST['repo']));
$repo = $repoPath[0];
$path = $repoPath[1];
$objectList = new SortingIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST), 'alphasort');

// Finally, we have our ordered list, so display
$i=0;
$dirListArray = array();
$dirSHAArray = array();
$dirTypeArray = array();
$finfo = finfo_open(FILEINFO_MIME_TYPE);
foreach ($objectList as $objectRef) {
	$fileFolderName = rtrim(substr($objectRef->getPathname(), strlen($path)),"..");
	if ($objectRef->getFilename()!="." && $fileFolderName[strlen($fileFolderName)-1]!="/") {
			$contents = file_get_contents($path.$fileFolderName);
			if (strpos(finfo_file($finfo, $path.$fileFolderName),"text")===0) {
				$contents = str_replace("\r","",$contents);
			};
			$store = "blob ".strlen($contents)."\000".$contents;
			$i++;
			array_push($dirListArray,ltrim($fileFolderName,"/"));
			array_push($dirSHAArray,sha1($store));
			$type = is_dir($path.$fileFolderName) ? "dir" : "file";
			array_push($dirTypeArray,$type);
	}
}
finfo_close($finfo);

echo PHP_EOL.PHP_EOL.'<script>'.PHP_EOL;
echo 'dirListArray = [';
for ($i=0;$i<count($dirListArray);$i++) {
	echo "'".$dirListArray[$i]."'";
	if ($i<count($dirListArray)-1) {echo ",";};
}
echo '];'.PHP_EOL;
echo 'dirSHAArray = [';
for ($i=0;$i<count($dirSHAArray);$i++) {
	echo "'".$dirSHAArray[$i]."'";
	if ($i<count($dirSHAArray)-1) {echo ",";};
}
echo '];'.PHP_EOL;
echo 'dirTypeArray = [';
for ($i=0;$i<count($dirTypeArray);$i++) {
	echo "'".$dirTypeArray[$i]."'";
	if ($i<count($dirTypeArray)-1) {echo ",";};
}
echo '];'.PHP_EOL;
echo '</script>';
?>
	
<div id="compareList" class="mainContainer"></div>
	
<div id="commitPane" class="commitPane">
<b style='font-size: 18px'>COMMIT CHANGES:</b><br><br>
<form name="fcForm" action="file-control.php" target="fileControl" method="POST">
<input type="text" name="title" value="Title..." style="width: 260px; border: 0; background: #f8f8f8; margin-bottom: 10px" onFocus="titleDefault='Title...'; if(this.value==titleDefault) {this.value=''}" onBlur="if(this.value=='') {this.value=titleDefault}"><br>
<textarea name="message" style="width: 260px; height: 180px; border: 0; background: #f8f8f8; margin-bottom: 5px" onFocus="messageDefault='Message...'; if(this.value==messageDefault) {this.value=''}" onBlur="if(this.value=='') {this.value=messageDefault}">Message...</textarea>
<input type="hidden" name="token" value="<?php echo strClean($_POST['token']);?>">
<input type="hidden" name="username" value="<?php echo strClean($_POST['username']);?>">
<input type="hidden" name="password" value="<?php echo strClean($_POST['password']);?>">
<input type="hidden" name="path" value="<?php echo $path; ?>">	
<input type="hidden" name="rowID" value="">
<input type="hidden" name="gitRepo" value="<?php echo $repo; ?>">
<input type="hidden" name="repo" value="">
<input type="hidden" name="dir" value="">
<input type="hidden" name="action" value="">
<input type="submit" name="commit" value="Commit changes" onClick="return commitChanges()" style="border: 0; background: #555; color: #fff; cursor: pointer">
</form>
</div>
	
<div id="infoPane" class="infoPane"></div>
	
<script>
top.fcFormAlias = document.fcForm;
var github = new Github(<?php
if ($_POST['token']!="") {
	echo '{token: "'.strClean($_POST['token']).'", auth: "oauth"}';
} else{
	echo '{username: "'.strClean($_POST['username']).'", password: "'.strClean($_POST['password']).'", auth: "basic"}';
}?>);

repoListArray = [];
repoSHAArray = [];
gitCommand = function(comm,value) {
	if (comm=="repo.show") {
		repoDir = value.split("@");
		user = repoDir[0].split("/")[0];
		repo = repoDir[0].split("/")[1];
		dir = repoDir[1];		
		var repo = github.getRepo(user,repo);
		var compareList = "";
		rowID = 0;
 		repo.getTree('master?recursive=true', function(err, tree) {
			for (i=0;i<tree.length;i++) {
				repoListArray.push(tree[i].path);
				repoSHAArray.push(tree[i].sha);
			}
			compareList += "<b style='font-size: 18px'>CHANGED FILES:</b><br><br>";
			newFilesList = "";
			top.rowCount=0;
			top.changedCount=0;
			top.newCount=0;
			top.deletedCount=0;
			for (i=0;i<dirListArray.length;i++) {
				repoArrayPos = repoListArray.indexOf(dirListArray[i]);
				if (dirTypeArray[i]=="dir") {
					fileExt = "folder";
				} else {
					fileExt = dirListArray[i].substr(dirListArray[i].lastIndexOf('.')+1);
				}
				if (repoArrayPos == "-1") {
					rowID++;
					styleExtra = fileExt == 'folder' ? ' style="cursor: default"' : '';
					clickExtra = fileExt != 'folder' ? ' onClick="getContent('+rowID+',\''+dirListArray[i]+'\')"' : '';
					githubExtra = 'onClick="pullContent('+rowID+',\'<?php echo $path."/"; ?>'+dirListArray[i]+'\',\''+dirListArray[i]+'\',\'new\')"';
					
					newFilesList += "<div class='row' id='row"+rowID+"'"+clickExtra+styleExtra+">";
					newFilesList += "<input type='checkbox' class='checkbox' id='checkbox"+rowID+"' onMouseOver='overOption=true' onMouseOut='overOption=false' onClick='updateSelection(this,"+rowID+",\"<?php echo $path;?>/"+dirListArray[i]+"\",\"new\")'>";
					newFilesList += "<div class='icon ext-"+fileExt+"'></div>"+dirListArray[i];
					newFilesList += "<div class='pullGithub' style='left: 815px' onMouseOver='overOption=true' onMouseOut='overOption=false' "+githubExtra+">Delete from server</div><br>";
					newFilesList += "</div>";
					
					newFilesList += "<span class='rowContent' id='row"+rowID+"Content'></span>";
					top.rowCount++;
					top.newCount++;
					
				} else if (dirTypeArray[i] == "file" && dirSHAArray[i] != repoSHAArray[repoArrayPos]) {
					rowID++;
					styleExtra = fileExt == 'folder' ? ' style="cursor: default"' : '';
					clickExtra = fileExt != 'folder' ? ' onClick="getContent('+rowID+',\''+dirListArray[i]+'\')"' : '';
					githubExtra = 'onClick="pullContent('+rowID+',\'<?php echo $path."/"; ?>'+dirListArray[i]+'\',\''+dirListArray[i]+'\',\'changed\')"';
					
					compareList += "<div class='row' id='row"+rowID+"'"+clickExtra+styleExtra+">";
					compareList += "<input type='checkbox' class='checkbox' id='checkbox"+rowID+"' onMouseOver='overOption=true' onMouseOut='overOption=false' onClick='updateSelection(this,"+rowID+",\"<?php echo $path;?>/"+dirListArray[i]+"@<?php echo $repo;?>/"+dirListArray[i]+"\",\"changed\")'>";
					compareList += "<div class='icon ext-"+fileExt+"'></div>"+dirListArray[i];
					compareList += "<div class='pullGithub' onMouseOver='overOption=true' onMouseOut='overOption=false' "+githubExtra+">Pull from Github</div><br>";
					compareList += "</div>";
					
					compareList += "<span class='rowContent' id='row"+rowID+"Content'></span>";
					top.rowCount++;
					top.changedCount++;
				}
			}

			compareList += "<br><br><b style='font-size: 18px'>NEW FILES:</b><br><br>"+newFilesList;
			
			delFilesList = "";
			for (i=0;i<repoListArray.length;i++) {
				dirArrayPos = dirListArray.indexOf(repoListArray[i]);
				if (repoListArray[i].lastIndexOf('/') > repoListArray[i].lastIndexOf('.')) {
					fileExt = "folder";
				} else {
					fileExt = repoListArray[i].substr(repoListArray[i].lastIndexOf('.')+1);
				}
				if (dirArrayPos == "-1") {
					rowID++;
					styleExtra = fileExt == 'folder' ? ' style="cursor: default"' : '';
					clickExtra = fileExt != 'folder' ? ' onClick="getContent('+rowID+',\''+repoListArray[i]+'\')"' : '';
					githubExtra = 'onClick="pullContent('+rowID+',\'<?php echo $path."/"; ?>'+repoListArray[i]+'\',\''+repoListArray[i]+'\',\'deleted\')"';
					
					delFilesList += "<div class='row' id='row"+rowID+"'"+clickExtra+styleExtra+">";
					delFilesList += "<input type='checkbox' class='checkbox' id='checkbox"+rowID+"' onMouseOver='overOption=true' onMouseOut='overOption=false' onClick='updateSelection(this,"+rowID+",\"<?php echo $repo;?>/"+repoListArray[i]+"\",\"deleted\")'>";
					delFilesList += "<div class='icon ext-"+fileExt+"'></div>"+repoListArray[i];
					delFilesList += "<div class='pullGithub' onMouseOver='overOption=true' onMouseOut='overOption=false' "+githubExtra+">Pull from Github</div><br>";
					delFilesList += "</div>";
					
					delFilesList += "<span class='rowContent' id='row"+rowID+"Content'></span>";
					top.rowCount++;
					top.deletedCount++;
				}
			}
			
			compareList += "<br><br><b style='font-size: 18px'>DELETED FILES:</b><br><br>"+delFilesList;
			get('compareList').innerHTML = compareList;
			updateInfo();
			get('blackMask','top').style.display='none';
			}
		)
	}
}
	
getContent = function(thisRow,path) {
	if("undefined" == typeof overOption || !overOption) {
		if ("undefined" == typeof lastRow || lastRow!=thisRow || get('row'+thisRow+'Content').innerHTML=="") {
			for (i=1;i<=rowID;i++) {
				get('row'+i+'Content').innerHTML = "";
				get('row'+i+'Content').style.display = "none";
			}
			repo = "<?php echo $repo;?>" + "/" + path;
			dir = "<?php echo $path;?>" + "/" + path;
			document.fcForm.rowID.value = thisRow;
			document.fcForm.repo.value = repo;
			document.fcForm.dir.value = dir;
			document.fcForm.action.value = "view";
			document.fcForm.submit();
		} else {
			get('row'+thisRow+'Content').innerHTML = "";
			get('row'+thisRow+'Content').style.display = "none";
		}
		lastRow = thisRow;
	}
}

top.selRowArray = [];
top.selRepoDirArray = [];
top.selActionArray = [];
updateSelection = function(elem,row,repoDir,action) {
	if (elem.checked) {
		top.selRowArray.push(row);
		top.selRepoDirArray.push(repoDir);
		top.selActionArray.push(action);
	} else {
		arrayIndex = top.selRowArray.indexOf(row);
		top.selRowArray.splice(arrayIndex,1);
		top.selRepoDirArray.splice(arrayIndex,1);
		top.selActionArray.splice(arrayIndex,1);
	};
}

commitChanges = function() {
	if(top.selRowArray.length>0) {
		if (document.fcForm.title.value!="Title..." && document.fcForm.message.value!="Message...") {
			get('blackMask','top').style.display = "block";
			top.selRowValue = "";
			top.selDirValue = "";
			top.selRepoValue = "";
			top.selActionValue = "";
			for (i=0;i<top.selRowArray.length;i++) {
				top.selRowValue += top.selRowArray[i];
				if (top.selActionArray[i]=="changed") {
					top.selDirValue += top.selRepoDirArray[i].split('@')[0];
					top.selRepoValue += top.selRepoDirArray[i].split('@')[1];
				}
				if (top.selActionArray[i]=="new") {
					top.selDirValue += top.selRepoDirArray[i];
					top.selRepoValue += "";
				}
				if (top.selActionArray[i]=="deleted") {
					top.selDirValue += "";
					top.selRepoValue += top.selRepoDirArray[i];
				}
				top.selActionValue += top.selActionArray[i];
				if (i<top.selRowArray.length-1) {
					top.selRowValue += ",";
					top.selDirValue += ",";
					top.selRepoValue += ",";
					top.selActionValue += ",";
				}
			}
			document.fcForm.rowID.value = top.selRowValue;
			document.fcForm.dir.value = top.selDirValue;
			document.fcForm.repo.value = top.selRepoValue;
			document.fcForm.action.value = top.selActionValue;
			document.fcForm.submit();
		} else {
			alert('Please enter a title & message for the commit');		
		}
	} else {
		alert('Please select some files/folders to commit');
	}
	return false;
}
	
gitCommand('repo.show','<?php echo strClean($_POST['repo']);?>');
</script>
	
<iframe name="fileControl" style="display: none"></iframe>
	
</body>
	
</html>