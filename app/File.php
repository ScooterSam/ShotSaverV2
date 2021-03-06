<?php

namespace App;

use App\Traits\Favourable;
use App\Traits\FileOperations;
use App\Traits\Viewable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class File extends Model
{
	use Viewable, Favourable, FileOperations;

	protected $guarded = ['id'];


	protected $casts = ['meta' => 'array'];

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function views()
	{
		return $this->hasMany(FileView::class);
	}

	public function favourites()
	{
		return $this->hasMany(FileFavourite::class);
	}

	public function file($file)
	{
		if ($this->{$file} === null) {
			return null;
		}

		if (!$this->private) {
			return Storage::cloud()->url($this->{$file});
		}

		return Storage::cloud()->temporaryUrl($this->{$file}, now()->addMinutes(60));
	}

	public function codeFileContents()
	{
		if ($this->type !== 'code' && $this->type !== 'text') {
			throw new \Exception('This file is not a code file.');
		}

		return Storage::cloud()->get($this->hd);

	}

	function formatSizeUnits()
	{
		$bytes = $this->size_in_bytes;

		if ($bytes >= 1073741824) {
			$bytes = number_format($bytes / 1073741824, 2) . ' GB';
		} elseif ($bytes >= 1048576) {
			$bytes = number_format($bytes / 1048576, 2) . ' MB';
		} elseif ($bytes >= 1024) {
			$bytes = number_format($bytes / 1024, 2) . ' KB';
		} elseif ($bytes > 1) {
			$bytes = $bytes . ' bytes';
		} elseif ($bytes == 1) {
			$bytes = $bytes . ' byte';
		} else {
			$bytes = '0 bytes';
		}

		return $bytes;
	}

	/**
	 * This will set our file public/private on our S3 storage
	 * Delete the original non-processed files and set our status as complete
	 */
	public function finishProcessing()
	{
		//Set the S3 visibility
		/*if($this->private) {
			$this->setPrivate();
		} else {
			$this->setPublic();
		}*/

		//Delete the temporary folder that was created to process the files...
		Storage::disk('processing')->deleteDirectory($this->name);

		//Now we set the file as complete
		$this->status = 'complete';
		$this->save();
	}

}
