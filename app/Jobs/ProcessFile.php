<?php

namespace App\Jobs;

use App\Services\FileValidation;
use App\User;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPVideoToolkit\Config;
use PHPVideoToolkit\Format;
use PHPVideoToolkit\Image;
use PHPVideoToolkit\ImageFormat_Jpg;
use PHPVideoToolkit\Timecode;
use PHPVideoToolkit\Video;
use PHPVideoToolkit\VideoFormat;
use Symfony\Component\HttpFoundation\File\File;

class ProcessFile implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	protected $tries = 1;

	public $timeout = 0;

	private $userId;
	private $file;
	private $originalFile;
	private $directory;
	/**
	 * @var array
	 */
	private $videoPaths;
	private $imagePaths;
	private $gifPaths;

	private $fileExtension;

	/**
	 * Create a new job instance.
	 *
	 * @return void
	 */
	public function __construct($file, $userId, $originalFile, $directory)
	{
		$this->userId       = $userId;
		$this->originalFile = $originalFile;
		$this->directory    = $directory;

		$this->fileExtension = pathinfo(\storage_path('app/' . $this->originalFile), PATHINFO_EXTENSION);

		$this->videoPaths = [
			'original' => storage_path('app/' . $this->originalFile),
			'thumb'    => storage_path('app/files-to-process/' . $this->directory . '/thumbnail.jpeg'),
			'thumb-hd' => storage_path('app/files-to-process/' . $this->directory . '/thumbnail-hd.jpeg'),
			'hd'       => storage_path('app/files-to-process/' . $this->directory . '/transcoded-hd.mp4'),
			'sd'       => storage_path('app/files-to-process/' . $this->directory . '/transcoded-sd.mp4'),
		];
		$this->imagePaths = [
			'original' => storage_path('app/' . $this->originalFile),
			'thumb'    => storage_path('app/files-to-process/' . $this->directory . '/thumbnail.jpeg'),
			'hd'       => storage_path('app/files-to-process/' . $this->directory . '/hd.jpeg'),
			'sd'       => storage_path('app/files-to-process/' . $this->directory . '/sd.jpeg'),
		];
		$this->gifPaths   = [
			'original' => storage_path('app/' . $this->originalFile),
			'thumb'    => storage_path('app/files-to-process/' . $this->directory . '/thumbnail.jpeg'),
			'hd'       => storage_path('app/files-to-process/' . $this->directory . '/hd.gif'),
			'sd'       => storage_path('app/files-to-process/' . $this->directory . '/sd.gif'),
		];

		$this->file = $file;
	}

	/**
	 * Execute the job.
	 *
	 * @return void
	 */
	public function handle()
	{
		if (app()->environment() === 'local') {
			$config = new Config([
				'ffmpeg'         => 'C:/ffmpeg/bin/ffmpeg.exe',
				'ffprobe'        => 'C:/ffmpeg/bin/ffprobe.exe',
				'temp_directory' => \storage_path('temp'),
			], true);
		} else {
			$config = new Config([
				'gifsicle' => '/usr/bin/gifsicle',
				'convert'  => '/usr/local/bin/convert',
			]);

		}
		$filePath = \storage_path('app/' . $this->originalFile);
		$fileType = app(FileValidation::class)->fileType(\mime_content_type($filePath));

		//dump(\mime_content_type(\storage_path('app/' . $this->originalFile)));

		if ($fileType === 'image') {
			$imageInfo = getimagesize($filePath);

			if ($imageInfo === false) {
				throw new Exception('This isnt an image file...');
			}

			if ($imageInfo[2] === IMAGETYPE_GIF) {
				$this->handleGif($config);
			} else {
				$this->handleImage();
			}
		} elseif ($fileType === 'video') {
			$this->handleVideo();
		} else {
			$this->handleRegularFile();
		}

		//If our file isnt a video we'll send our webhooks
		//Webhooks are triggered by the processing server for videos.
		if($fileType !== 'video') {
			dispatch(new SendWebhooks(request('file_id')));
		}

	}

	public function handleGif(Config $config)
	{

		$format = Format::getFormatFor($this->gifPaths['hd'], $config, 'ImageFormat');
		$format->setVideoFrameRate(12);

		$gifAsImage = new Image($this->gifPaths['original'], $config);
		$dimensions = $gifAsImage->readDimensions();


		//Save thumbnail
		$image  = new Image($this->gifPaths['original'], $config);
		$format = new ImageFormat_Jpg();
		$format->setVideoDimensions($dimensions['width'] / 4, $dimensions['height'] / 4);
		$image->save($this->gifPaths['thumb'], $format);

		//Save files to minio storage
		Storage::cloud()->putFileAs($this->directory, new File($this->gifPaths['thumb']), 'thumb.jpeg', 'public');
		Storage::cloud()->putFileAs($this->directory, new File($this->gifPaths['original']), 'hd.gif', 'public');

		//Update the file information that is stored in the database
		$this->file->mime_type     = mime_content_type($this->gifPaths['original']);
		$this->file->type          = app(FileValidation::class)->fileType($this->file->mime_type);
		$this->file->hd            = "{$this->directory}/hd.gif";
		$this->file->thumb         = "{$this->directory}/thumb.jpeg";
		$this->file->thumb_hd      = "{$this->directory}/hd.jpeg";
		$this->file->size_in_bytes = (filesize($this->gifPaths['original']) + filesize($this->gifPaths['thumb']));
		//$this->file->status        = 'complete';
		$this->file->meta          = $this->meta($dimensions, ['hd', 'thumb']);
		$this->file->save();

		$this->file->finishProcessing();
	}

	public function handleImage()
	{

		//Save HD original
		$image      = new Image($this->imagePaths['original']);
		$dimensions = $image->readDimensions();
		$format     = new ImageFormat_Jpg();
		$image->save($this->imagePaths['hd'], $format);

		//Save LD version
		$image  = new Image($this->imagePaths['original']);
		$format = new ImageFormat_Jpg();

		$format->setVideoDimensions($dimensions['width'] / 2, $dimensions['height'] / 2);
		$image->save($this->imagePaths['sd'], $format);

		//Save thumbnail
		$image  = new Image($this->imagePaths['original']);
		$format = new ImageFormat_Jpg();
		$format->setVideoDimensions($dimensions['width'] / 4, $dimensions['height'] / 4);
		$image->save($this->imagePaths['thumb'], $format);

		//Save files to minio storage
		Storage::cloud()->putFileAs($this->directory, new File($this->imagePaths['thumb']), 'thumb.jpeg', 'public');
		Storage::cloud()->putFileAs($this->directory, new File($this->imagePaths['hd']), 'hd.jpeg', 'public');
		Storage::cloud()->putFileAs($this->directory, new File($this->imagePaths['sd']), 'sd.jpeg', 'public');

		//Update the file information that is stored in the database
		$this->file->mime_type     = mime_content_type($this->imagePaths['hd']);
		$this->file->type          = app(FileValidation::class)->fileType($this->file->mime_type);
		$this->file->hd            = "{$this->directory}/hd.jpeg";
		$this->file->sd            = "{$this->directory}/sd.jpeg";
		$this->file->thumb         = "{$this->directory}/thumb.jpeg";
		$this->file->thumb_hd      = "{$this->directory}/hd.jpeg";
		$this->file->size_in_bytes = (filesize($this->imagePaths['hd']) + filesize($this->imagePaths['sd']) + filesize($this->imagePaths['thumb']));
		//$this->file->status        = 'complete';
		$this->file->meta          = $this->meta($dimensions, ['hd', 'sd', 'thumb']);
		$this->file->save();
		$this->file->finishProcessing();
	}

	public function handleVideo()
	{

		$user = User::find($this->userId);

		$params = [
			'directory' => $this->directory,
			'folder'    => storage_path('app/files-to-process/' . $this->directory),
			'file'      => storage_path('app/' . $this->originalFile),
			'file_id'   => $this->file->id,
			'key'       => env('SECURE_KEY'),
			'user_id'   => $user->id,
		];

		$client = new \GuzzleHttp\Client();

		$url = 'https://processing.shotsaver.io/process';
		$client->post($url, [
			'form_params' => $params,
		]);

		return;


		$video = new Video($this->videoPaths['original']);

		$od = $video->getOptimalDimensions(1920, 1080);

		//Create transcoded 1920x1080 video
		$outputFormat = new VideoFormat();
		$outputFormat->setVideoFrameRate(30);
		$outputFormat->setAudioCodec('aac');
		$outputFormat->setVideoCodec('h264');
		$outputFormat->setAudioChannels(1);
		$outputFormat->setVideoBitrate(($od['padded_width'] * 1.5) + 500 . 'k');
		$outputFormat->setAudioBitrate('128k');
		$outputFormat->setVideoDimensions($od['padded_width'], $od['padded_height']);
		$video->save($this->videoPaths['hd'], $outputFormat);

		//Create 1920x1080 hd thumbnail
		$video        = new Video($this->videoPaths['hd']);
		$outputFormat = new VideoFormat();
		$od           = $video->getOptimalDimensions(1920, 1080);
		$outputFormat->setVideoDimensions($od['padded_width'], $od['padded_height']);
		$video->extractFrame(new Timecode(2))
			->save($this->videoPaths['thumb-hd'], $outputFormat);

		//Create 640x360 ld thumbnail
		$video        = new Video($this->videoPaths['hd']);
		$outputFormat = new VideoFormat();
		$od           = $video->getOptimalDimensions(640, 360);
		$outputFormat->setVideoDimensions($od['padded_width'], $od['padded_height']);
		$video->extractFrame(new Timecode(2))
			->save($this->videoPaths['thumb'], $outputFormat);


		//Create 1280x720 video (low def)
		$video        = new Video($this->videoPaths['hd']);
		$od           = $video->getOptimalDimensions(1280, 720);
		$outputFormat = new VideoFormat();
		$outputFormat->setVideoDimensions($od['padded_width'], $od['padded_height']);
		$outputFormat->setVideoBitrate(($od['padded_width'] * 1.5) + 500 . 'k');
		$video->save($this->videoPaths['sd'], $outputFormat);

		//Save files to minio storage
		Storage::cloud()->putFileAs($this->directory, new File($this->videoPaths['thumb']), 'thumb.jpeg', 'public');
		Storage::cloud()->putFileAs($this->directory, new File($this->videoPaths['thumb-hd']), 'thumb-hd.jpeg', 'public');
		Storage::cloud()->putFileAs($this->directory, new File($this->videoPaths['hd']), 'hd.mp4', 'public');
		Storage::cloud()->putFileAs($this->directory, new File($this->videoPaths['sd']), 'sd.mp4', 'public');

		//Update the file information that is stored in the database
		$this->file->mime_type     = mime_content_type($this->videoPaths['hd']);
		$this->file->type          = app(FileValidation::class)->fileType($this->file->mime_type);
		$this->file->extension     = $this->fileExtension;
		$this->file->hd            = "{$this->directory}/hd.mp4";
		$this->file->sd            = "{$this->directory}/sd.mp4";
		$this->file->thumb         = "{$this->directory}/thumb.jpeg";
		$this->file->thumb_hd      = "{$this->directory}/thumb-hd.jpeg";
		$this->file->size_in_bytes = ((filesize($this->videoPaths['hd']) + filesize($this->videoPaths['sd'])) + filesize($this->videoPaths['thumb']));
		$this->file->status        = 'complete';
		$this->file->save();

	}

	public function handleRegularFile()
	{
		$filePath = storage_path('app/' . $this->originalFile);

		$fileName = Str::random() . '.' . pathinfo($filePath, PATHINFO_EXTENSION);

		$fileInfo = Storage::cloud()->putFileAs($this->directory, new File($filePath), $fileName, 'public');

		//dump($fileInfo);

		//Update the file information that is stored in the database
		$this->file->mime_type = mime_content_type($filePath);
		$this->file->extension = $this->fileExtension;
		$this->file->hd        = $fileInfo;
		/* $this->file->sd            = "{$this->directory}/sd.mp4";
		 $this->file->thumb         = "{$this->directory}/thumb.jpeg";
		 $this->file->thumb_hd      = "{$this->directory}/thumb-hd.jpeg";*/
		$this->file->size_in_bytes = filesize($filePath);
		//$this->file->status        = 'complete';

		if ($codeInfo = app(FileValidation::class)->isCodeFile($this->fileExtension)) {
			$this->file->meta = $codeInfo;
			$this->file->type = 'code';
		} else {
			$this->file->type = app(FileValidation::class)->fileType($this->file->mime_type);
		}

		$this->file->save();
		$this->file->finishProcessing();

	}

	public function failed(Exception $exception)
	{

		$this->file->status = 'failed';
		$this->file->meta   = [
			'message' => $exception->getMessage(),
			'trace'   => $exception->getTrace(),
			'line'    => $exception->getLine(),
		];

		$this->file->save();
	}

	public function meta($dimensions, $sizes)
	{
		$dimensionsMeta = [];
		if (in_array('hd', $sizes)) {
			$dimensionsMeta['hd'] = [$dimensions['width'], $dimensions['height']];
		}
		if (in_array('sd', $sizes)) {
			$dimensionsMeta['sd'] = [$dimensions['width'] / 2, $dimensions['height'] / 2];
		}
		if (in_array('thumb', $sizes)) {
			$dimensionsMeta['thumb'] = [$dimensions['width'] / 4, $dimensions['height'] / 4];
		}

		return [
			'dimensions' => $dimensionsMeta,
		];
	}
}
