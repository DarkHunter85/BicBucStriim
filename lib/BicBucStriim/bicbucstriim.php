<?php

require_once 'utilities.php';

class BicBucStriim {
	# Name to the bbs db
	const DBNAME = 'data.db';
	# Thumbnail dimension (they are square)
	const THUMB_RES = 160;

	# bbs sqlite db
	var $mydb = NULL;
	# calibre sqlite db
	var $calibre = NULL;
	# calibre library dir
	var $calibre_dir = '';
	# calibre library file, last modified date
	var $calibre_last_modified;
	# last sqlite error
	var $last_error = 0;
	# dir for bbs db
	var $data_dir = '';
	# dir for generated thumbs
	var $thumb_dir = '';

	static function checkForCalibre($path) {
		$rp = realpath($path);
		$rpm = $rp.'/metadata.db';
		return is_readable($rpm);
	}

	# Open the BBS DB. The thumbnails are stored in the same directory as the db.
	function __construct($dataPath='data/data.db') {
		$rp = realpath($dataPath);
		$this->data_dir = dirname($dataPath);
		$this->thumb_dir = $this->data_dir;
		if (file_exists($rp) && is_writeable($rp)) {
			$this->calibre_last_modified = filemtime($rp);
			$this->mydb = new PDO('sqlite:'.$rp, NULL, NULL, array());
			$this->mydb->setAttribute(1002, 'SET NAMES utf8');
			$this->mydb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->mydb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			$this->last_error = $this->mydb->errorCode();
		} else {
			$this->mydb = NULL;
		}
	}

	function openCalibreDB($calibrePath) {
		$rp = realpath($calibrePath);
		$this->calibre_dir = dirname($rp);
		if (file_exists($rp) && is_readable($rp)) {
			$this->calibre = new PDO('sqlite:'.$rp, NULL, NULL, array());
			$this->calibre->setAttribute(1002, 'SET NAMES utf8');
			$this->calibre->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->calibre->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			$this->last_error = $this->calibre->errorCode();
		} else {
			$this->calibre = NULL;
		}
	}

	# Is our own DB open?
	function dbOk() {
		return (!is_null($this->mydb));
	}

	# Execute a query $sql on the settings DB and return the 
	# result as an array of objects of class $class
	function sfind($class, $sql) {
		$stmt = $this->mydb->query($sql,PDO::FETCH_CLASS, $class);		
		$this->last_error = $stmt->errorCode();
		$items = $stmt->fetchAll();
		$stmt->closeCursor();	
		return $items;
	}

	function configs() {
		return $this->sfind('Config','select * from configs');	
	}
	function saveConfigs($configs) {
		$sql = 'update configs set val=:val where name=:name';
		$stmt = $this->mydb->prepare($sql);
		$this->mydb->beginTransaction();
		#$this->mydb->exec('delete from configs');
		foreach ($configs as $config) {
			$stmt->execute(array('name' => $config->name, 'val' => $config->val));
		}
		$this->mydb->commit();
	}

	############# Calibre Library functions ################

	# Is the Calibre library open?
	function libraryOk() {
		return (!is_null($this->calibre));
	}

	# Execute a query $sql on the Calibre DB and return the 
	# result as an array of objects of class $class
	function find($class, $sql) {
		$stmt = $this->calibre->query($sql,PDO::FETCH_CLASS, $class);		
		$this->last_error = $stmt->errorCode();
		$items = $stmt->fetchAll();
		$stmt->closeCursor();	
		return $items;
	}

	# Return a single object or NULL if not found
	function findOne($class, $sql) {
		$result = $this->find($class, $sql);
		if ($result == NULL || $result == FALSE)
			return NULL;
		else
			return $result[0];
	}

	/**
	 * Return a slice of entries defined by the parameters $index and $length.
	 * If $search is defined it is used to filter the titles, ignoring case.
	 * Return an array with elements: current page, no. of pages, $length entries
	 * 
	 * @param  string  $class       name of class to return
	 * @param  integer $index=0     page index
	 * @param  integer $length=100  length of page
	 * @param  string  $search=NULL search pattern for sort/name fields
	 * @return array                an array with current page (key 'page'),
	 *                              number of pages (key 'pages'),
	 *                              an array of $class instances (key 'entries') or NULL
	 */
	function findSlice($class, $index=0, $length=100, $search=NULL, $id=NULL) {	
	if ($index < 0 || $length < 1 || !in_array($class, array('Book','Author','Tag', 'Series', 'SingleSeries', 'SingleTag', 'SingleAuthor')))
			return array('page'=>0,'pages'=>0,'entries'=>NULL);
		$offset = $index * $length;	
		
		switch($class) {
			case 'Author': 
				if (is_null($search)) {
					$count = 'select count(*) from authors';
					$query = 'select a.id, a.name, a.sort, count(bal.id) as anzahl from authors as a left join books_authors_link as bal on a.id = bal.author group by a.id order by a.sort limit '.$length.' offset '.$offset;
				}	else {
					$count = 'select count(*) from authors where lower(sort) like \'%'.strtolower($search).'%\'';
					$query = 'select a.id, a.name, a.sort, count(bal.id) as anzahl from authors as a left join books_authors_link as bal on a.id = bal.author where lower(a.name) like \'%'.strtolower($search).'%\' group by a.id order by a.sort limit '.$length.' offset '.$offset;	
				}				
				break;
			case 'SingleAuthor':
			if (is_null($search)) {
        $count = 'select count(*) from authors where id='.$id;
        $query = 'select BAL.book, Books.* from books_authors_link BAL, books Books where Books.id=BAL.book and author = '.$id.' order by Books.sort limit '.$length.' offset '.$offset;
      } else {
        $count = 'select count(*) from (select BAL.book, Books.* from books_authors_link BAL, books Books where Books.id=BAL.book and author = '.$id.') where lower(sort) like \'%'.strtolower($search).'%\'';
        $query = 'select BAL.book, Books.* from books_authors_link BAL, books Books where Books.id=BAL.book and author ='.$id.' and lower(Books.sort) like \'%'.strtolower($search).'%\' order by Books.sort limit '.$length.' offset '.$offset;
      }
      break;
			case 'Book': 
				if (is_null($search)) {
					$count = 'select count(*) from books';
					$query = 'select * from books order by sort limit '.$length.' offset '.$offset;
				}	else {
					$count = 'select count(*) from books where lower(title) like \'%'.strtolower($search).'%\'';
					$query = 'select * from books where lower(title) like \'%'.strtolower($search).'%\' order by sort limit '.$length.' offset '.$offset;	
				}
				break;
			case 'Series': 
				if (is_null($search)) {
					$count = 'select count(*) from series';
					$query = 'select series.id, series.name, count(bsl.id) as anzahl from series left join books_series_link as bsl on series.id = bsl.series group by series.id order by series.name limit '.$length.' offset '.$offset;
				}	else {
					$count = 'select count(*) from series where lower(name) like \'%'.strtolower($search).'%\'';
					$query = 'select series.id, series.name, count(bsl.id) as anzahl from series left join books_series_link as bsl on series.id = bsl.series where lower(series.name) like \'%'.strtolower($search).'%\' group by series.id order by series.name limit '.$length.' offset '.$offset;	
				}
				break;
			case 'SingleSeries':
				if (is_null($search)) {
					$count = 'select count(*) from books_series_link where series = '.$id;
					$query = 'select BSL.book, Books.* from books_series_link BSL, books Books where Books.id=BSL.book and series = '.$id.' order by series_index limit '.$length.' offset '.$offset;	          
				} else {
					$count = 'select count (*) from (select BSL.book, Books.* from books_series_link BSL, books Books where Books.id=BSL.book and series = '.$id.') where lower(sort) like \'%'.strtolower($search).'%\'';
					$query = 'select BSL.book, Books.* from books_series_link BSL, books Books where Books.id=BSL.book and series = '.$id.' and lower(Books.sort) like \'%'.strtolower($search).'%\' order by series_index limit '.$length.' offset '.$offset;	
				}
				break;			
			case 'Tag': 
				if (is_null($search)) {
					$count = 'select count(*) from tags';
					$query = 'select tags.id, tags.name, count(btl.id) as anzahl from tags left join books_tags_link as btl on tags.id = btl.tag group by tags.id order by tags.name limit '.$length.' offset '.$offset;							
				}	else {
					$count = 'select count(*) from tags where lower(name) like \'%'.strtolower($search).'%\'';
					$query = 'select tags.id, tags.name, count(btl.id) as anzahl from tags left join books_tags_link as btl on tags.id = btl.tag where lower(tags.name) like \'%'.strtolower($search).'%\' group by tags.id order by tags.name limit '.$length.' offset '.$offset;	
				}
				break;
			case 'SingleTag':
				if (is_null($search)) {
					$count = 'select count(*) from books_tags_link where tag ='.$id;
					$query = 'select BTL.book, Books.* from books_tags_link BTL, books Books where Books.id=BTL.book and tag = '.$id.' order by Books.sort limit '.$length.' offset '.$offset;
				}	else {
					$count = 'select count (*) from (select BTL.book, Books.* from books_tags_link BTL, books Books where Books.id=BTL.book and tag = '.$id.') where lower(sort) like \'%'.strtolower($search).'%\'';
					$query = 'select BTL.book, Books.* from books_tags_link BTL, books Books where Books.id=BTL.book and tag = '.$id.' and lower(Books.sort) like \'%'.strtolower($search).'%\' order by Books.sort limit '.$length.' offset '.$offset;
				}			
				break;
		}
		$no_entries = $this->count($count);
		$no_pages = (int) ($no_entries / $length);
		if ($no_entries % $length > 0)
			$no_pages += 1;		
  	$entries = $this->find($class,$query); 	
		return array('page'=>$index, 'pages'=>$no_pages, 'entries'=>$entries);
	}

	# Return the number (int) of rows for a SQL COUNT Statement, e.g.
	# SELECT COUNT(*) FROM books;
	function count($sql) {
		$result = $this->calibre->query($sql)->fetchColumn(); 
		if ($result == NULL || $result == FALSE)
			return -1;
		else
			return (int) $result;
	}


	# Return the 30 most recent books
	function last30Books() {
		$books = $this->find('Book','select * from books order by timestamp desc limit 30');		
		return $books;
	}

	# Return a grouped list of all authors. The list is separated by dividers, 
	# the initial name character.
	function allAuthors() {
		#$authors = $this->find('Author','select * from authors order by sort');		
		$authors = $this->find('Author', 'select a.id, a.name, a.sort, count(bal.id) as anzahl from authors as a left join books_authors_link as bal on a.id = bal.author group by a.id order by a.sort');
		return $this->mkInitialedList($authors);
	}

	# Find a single author and return the details plus all books.
	function authorDetails($id) {
		$author = $this->findOne('Author', 'select * from authors where id='.$id);
		if (is_null($author)) return NULL;
		$book_ids = $this->find('BookAuthorLink', 'select * from books_authors_link where author='.$id);
		$books = array();
		foreach($book_ids as $bid) {
			$book = $this->title($bid->book);
			array_push($books, $book);
		}
		return array('author' => $author, 'books' => $books);
	}

	# Search a list of authors defined by the parameters $index and $length.
	# If $search is defined it is used to filter the names, ignoring case.
	# Return an array with elements: current page, no. of pages, $length entries
	function authorsSlice($index=0, $length=100, $search=NULL) {
		return $this->findSlice('Author', $index, $length, $search);
	}
	
	function authorDetailSlice($index=0, $id=NULL, $length=100, $search=NULL) {
    return $this->findSlice('SingleAuthor', $index, $length, $search, $id);
  }
	

	/**
	 * Find the initials of all authors and their count
	 * @return array an array of Items with initial character and author count
	 */
	function authorsInitials() {
		return $this->find('Item', 'select substr(upper(sort),1,1) as initial, count(*) as ctr from authors group by initial order by initial asc');
	}

	/**
	 * Find all authors with a given initial and return their names and book count
	 * @param  string $initial initial character of last name, uppercase
	 * @return array           array of authors with book count
	 */
	function authorsNamesForInitial($initial) {
		return $this->find('Author', 'select a.id, a.name, a.sort, count(bal.id) as anzahl from authors as a left join books_authors_link as bal on a.id = bal.author where substr(upper(a.sort),1,1) = \''.$initial.'\' group by a.id order by a.sort');	
	}

	# Returns a tag and the related books
	function tagDetails($id) {
		$tag = $this->findOne('Tag', 'select * from tags where id='.$id);
		if (is_null($tag)) return NULL;
		$book_ids = $this->find('BookTagLink', 'select * from books_tags_link where tag='.$id);
		$books = array();
		foreach($book_ids as $bid) {
			$book = $this->title($bid->book);
			array_push($books, $book);
		}
		return array('tag' => $tag, 'books' => $books);
	}

	# Return a grouped list of all tags. The list is separated by dividers, 
	# the initial character.
	function allTags() {
		#$tags = $this->find('Tag','select * from tags order by name');		
		$tags = $this->find('Tag', 'select tags.id, tags.name, count(btl.id) as anzahl from tags left join books_tags_link as btl on tags.id = btl.tag group by tags.id order by tags.name;');
		return $this->mkInitialedList($tags);
	}

	# Search a list of tags defined by the parameters $index and $length.
	# If $search is defined it is used to filter the tag names, ignoring case.
	# Return an array with elements: current page, no. of pages, $length entries
	function tagsSlice($index=0, $length=100, $search=NULL) {
		return $this->findSlice('Tag', $index, $length, $search);
	}
	
	
	function tagDetailSlice($index=0, $id=NULL, $length=100, $search=NULL) {
		return $this->findSlice('SingleTag', $index, $length, $search, $id);
	}		

	/**
	 * Find the initials of all tags and their count
	 * @return array an array of Items with initial character and tag count
	 */
	function tagsInitials() {
		return $this->find('Item', 'select substr(upper(name),1,1) as initial, count(*) as ctr from tags group by initial order by initial asc');
	}

	/**
	 * Find all authors with a given initial and return their names and book count
	 * @param  string $initial initial character of last name, uppercase
	 * @return array           array of authors with book count
	 */
	function tagsNamesForInitial($initial) {
		return $this->find('Tag', 'select tags.id, tags.name, count(btl.id) as anzahl from tags left join books_tags_link as btl on tags.id = btl.tag where substr(upper(tags.name),1,1) = \''.$initial.'\' group by tags.id order by tags.name');	
	}

	# Return a grouped list of all books. The list is separated by dividers, 
	# the initial title character.
	function allTitles() {
		$books = $this->find('Book','select * from books order by sort');
		return $this->mkInitialedList($books);
	}

	# Search a list of books defined by the parameters $index and $length.
	# If $search is defined it is used to filter the book title, ignoring case.
	# Return an array with elements: current page, no. of pages, $length entries
	function titlesSlice($index=0, $length=100, $search=NULL) {
		return $this->findSlice('Book', $index, $length, $search);
	}

	
	# Find only one book
	function title($id) {
		return $this->findOne('Book','select * from books where id='.$id);
	}

	# Returns the path to the cover image of a book or NULL.
	function titleCover($id) {
		$book = $this->title($id);
		if (is_null($book)) 
			return NULL;
		else
			return Utilities::bookPath($this->calibre_dir,$book->path,'cover.jpg');
	}

	/**
	 * Returns the path to a thumbnail of a book's cover image or NULL. 
	 * 
	 * If a thumbnail doesn't exist the function tries to make one from the cover.
	 * The thumbnail dimension generated is 160*160, which is more than what 
	 * jQuery Mobile requires (80*80). However, if we send the 80*80 resolution the 
	 * thumbnails look very pixely.
	 * @param  int 				$id book id
	 * @param  bool  			true = clip the thumbnail, else stuff it
	 * @return string     thumbnail path or NULL
	 */
	function titleThumbnail($id, $clipped) {
		$thumb_name = 'thumb_'.$id.'.png';
		$thumb_path = $this->thumb_dir.'/'.$thumb_name;
		if (!file_exists($thumb_path)) {
			$cover = $this->titleCover($id);
			if (is_null($cover))
				$thumb_path = NULL;
			else {
				if ($clipped)
					$created = $this->titleThumbnailClipped($cover, self::THUMB_RES, self::THUMB_RES, $thumb_path);
				else
					$created = $this->titleThumbnailStuffed($cover, self::THUMB_RES, self::THUMB_RES, $thumb_path);
				if (!$created)
					$thumb_path = NULL;
			}
		}
		return $thumb_path;
	}
	
	/**
	 * Create a square thumbnail by clipping the largest possible square from the cover
	 * @param  string $cover      path to book cover image
	 * @param  int 	 	$newwidth   required thumbnail width
	 * @param  int 		$newheight  required thumbnail height
	 * @param  string $thumb_path path for thumbnail storage
	 * @return bool             	true = thumbnail created
	 */
	function titleThumbnailClipped($cover, $newwidth, $newheight, $thumb_path) {
		list($width, $height) = getimagesize($cover);
		$thumb = imagecreatetruecolor($newwidth, $newheight);
		$source = imagecreatefromjpeg($cover);
		$minwh = min(array($width, $height));
		$newx = ($width / 2) - ($minwh / 2);
		$newy = ($height / 2) - ($minwh / 2);
		$inbetween = imagecreatetruecolor($minwh, $minwh);
		imagecopy($inbetween, $source, 0, 0, $newx, $newy, $minwh, $minwh);				
		imagecopyresized($thumb, $inbetween, 0, 0, 0, 0, $newwidth, $newheight, $minwh, $minwh);
		$created = imagepng($thumb, $thumb_path);				
		return $created;
	}

	/**
	 * Create a square thumbnail by stuffing the cover at the edges
	 * @param  string $cover      path to book cover image
	 * @param  int 	 	$newwidth   required thumbnail width
	 * @param  int 		$newheight  required thumbnail height
	 * @param  string $thumb_path path for thumbnail storage
	 * @return bool             	true = thumbnail created
	 */
	function titleThumbnailStuffed($cover, $newwidth, $newheight, $thumb_path) {
		list($width, $height) = getimagesize($cover);
		$thumb = Utilities::transparentImage($newwidth, $newheight);
		$source = imagecreatefromjpeg($cover);
		$dstx = 0;
		$dsty = 0;
		$maxwh = max(array($width, $height));
		if ($height > $width) {
			$diff = $maxwh - $width;
			$dstx = (int) $diff/2;
		} else {
			$diff = $maxwh - $height;
			$dsty = (int) $diff/2;
		}
		$inbetween = Utilities::transparentImage($maxwh, $maxwh);
		imagecopy($inbetween, $source, $dstx, $dsty, 0, 0, $width, $height);				
		imagecopyresampled($thumb, $inbetween, 0, 0, 0, 0, $newwidth, $newheight, $maxwh, $maxwh);
		$created = imagepng($thumb, $thumb_path);				
		imagedestroy($thumb);
		imagedestroy($inbetween);
		imagedestroy($source);
		return $created;
	}

	/**
	 * Delete existing thumbnail files
	 * @return bool false if there was an error
	 */
	function clearThumbnails() {
		$cleared = true;
		if($dh = opendir($this->thumb_dir)){
	    while(($file = readdir($dh)) !== false) {
	    	$fn = $this->thumb_dir.'/'.$file;
	      if(fnmatch("thumb*.png", $file) && file_exists($fn)) {
	      	if (!@unlink($fn)) {
	      		$cleared = false;
	      		break;
	      	}
	      }
	    }
			closedir($dh);
		} else 
			$cleared = false;
		return $cleared;
	}

	/**
	 * Find a single book, its authors, series, tags, formats and comment.
	 * @param  int 		$id 	the Calibre book ID
	 * @return array     		the book and its authors, series, tags, formats, and comment/description
	 */
	function titleDetails($id) {
		$book = $this->title($id);
		if (is_null($book)) return NULL;
		$author_ids = $this->find('BookAuthorLink', 'select * from books_authors_link where book='.$id);
		$authors = array();
		foreach($author_ids as $aid) {
			$author = $this->findOne('Author', 'select * from authors where id='.$aid->author);
			array_push($authors, $author);
		}
		$series_ids = $this->find('BookSeriesLink', 'select * from books_series_link where book='.$id);
		$series = array();
		foreach($series_ids as $aid) {
			$this_series = $this->findOne('Series', 'select * from series where id='.$aid->series);
			array_push($series, $this_series);
		}		
		$tag_ids = $this->find('BookTagLink', 'select * from books_tags_link where book='.$id);
		$tags = array();
		foreach($tag_ids as $tid) {
			$tag = $this->findOne('Tag', 'select * from tags where id='.$tid->tag);
			array_push($tags, $tag);
		}
		$formats = $this->find('Data', 'select * from data where book='.$id);
		$comment = $this->findOne('Comment', 'select * from comments where book='.$id);
		if (is_null($comment))
			$comment_text = '';
		else
			$comment_text = $comment->text;		
		return array('book' => $book, 'authors' => $authors, 'series' => $series, 'tags' => $tags, 
			'formats' => $formats, 'comment' => $comment_text);
	}

	/**
	 * Find a subset of the details for a book that is sufficient for an OPDS 
	 * partial acquisition feed. The function assumes that the book record has 
	 * already been loaded.
	 * @param  Book   $book complete book record from title()
	 * @return array       	the book and its authors, tags and formats
	 */
	function titleDetailsOpds($book) {
		if (is_null($book)) return NULL;
		$author_ids = $this->find('BookAuthorLink', 'select * from books_authors_link where book='.$book->id);
		$authors = array();
		foreach($author_ids as $aid) {
			$author = $this->findOne('Author', 'select * from authors where id='.$aid->author);
			array_push($authors, $author);
		}
		$tag_ids = $this->find('BookTagLink', 'select * from books_tags_link where book='.$book->id);
		$tags = array();
		foreach($tag_ids as $tid) {
			$tag = $this->findOne('Tag', 'select * from tags where id='.$tid->tag);
			array_push($tags, $tag);
		}
		$formats = $this->find('Data', 'select * from data where book='.$book->id);
		return array('book' => $book, 'authors' => $authors, 'tags' => $tags, 
			'formats' => $formats);
	}

	/**
	 * Retrieve the OPDS title details for a collection of Books and
	 * filter out the titles without a downloadable format.
	 *
	 * This is a utilty function for OPDS, because OPDS acquisition feeds don't 
	 * valdate if there are entries without acquisition links to downloadable files.
	 * 
	 * @param  array 	$books a collection of Book instances
	 * @return array         the book and its authors, tags and formats
	 */
	function titleDetailsFilteredOpds($books) {
		$filtered_books = array();
		foreach ($books as $book) {
			$record = $this->titleDetailsOpds($book);
			if (!empty($record['formats']))
				array_push($filtered_books,$record);
		}
		return $filtered_books;
	}

	# Returns the path to the cover image of a book or NULL.
	function titleFile($id, $file) {
		$book = $this->title($id);
		if (is_null($book)) 
			return NULL;
		else 
			return Utilities::bookPath($this->calibre_dir,$book->path,$file);
	}

	
	/**
	 * Find a single series and return the details plus all books.
	 * @param  int 		$id series id
	 * @return array  an array with series details (key 'series') and 
	 *                the related books (key 'books')
	 */
	function seriesDetails($id) {
		$series = $this->findOne('Series', 'select * from series where id='.$id);
		if (is_null($series)) return NULL;
		$books = $this->find('Book', 'select BSL.book, Books.* from books_series_link BSL, books Books where Books.id=BSL.book and series='.$id.' order by series_index');
		return array('series' => $series, 'books' => $books);
	}

  
  function seriesDetailsSlice ($index=0, $id=NULL, $length=100, $search=NULL)
  {
      return $this->findSlice('SingleSeries', $index, $length, $search, $id);
  }


	/**
	 * Search a list of books defined by the parameters $index and $length.
	 * If $search is defined it is used to filter the book title, ignoring case.
	 * Return an array with elements: current page, no. of pages, $length entries
	 * 
	 * @param  integer $index=0     page indes
	 * @param  integer $length=100  page length
	 * @param  string  $search=NULL search criteria for series name
	 * @return array                see findSlice
	 */
	function seriesSlice($index=0, $length=100, $search=NULL) {
		return $this->findSlice('Series', $index, $length, $search);
	}

	/**
	 * Find the initials of all series and their number
	 * @return array an array of Items with initial character and series count
	 */
	function seriesInitials() {
		return $this->find('Item', 'select substr(upper(name),1,1) as initial, count(*) as ctr from series group by initial order by initial asc');
	}

	/**
	 * Find all series with a given initial and return their names and book count
	 * @param  string $initial initial character of name, uppercase
	 * @return array           array of Series with book count
	 */
	function seriesNamesForInitial($initial) {
		return $this->find('Series', 'select series.id, series.name, count(btl.id) as anzahl from series left join books_series_link as btl on series.id = btl.series where substr(upper(series.name),1,1) = \''.$initial.'\' group by series.id order by series.name');	
	}

	# Generate a list where the items are grouped and separated by 
	# the initial character.
	# If the item has a 'sort' field that is used, else the name.
	function mkInitialedList($items) {
		$grouped_items = array();
		$initial_item = "";
		foreach ($items as $item) {
			if (isset($item->sort))
				$is = $item->sort;
			else 
				$is = $item->name;
			$ix = mb_strtoupper(mb_substr($is,0,1,'UTF-8'), 'UTF-8');
			if ($ix != $initial_item) {
				array_push($grouped_items, array('initial' => $ix));
				$initial_item = $ix;
			} 
			array_push($grouped_items, $item);
		}
		return $grouped_items;
	}
}
?>
