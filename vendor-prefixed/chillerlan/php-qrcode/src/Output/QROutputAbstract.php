<?php
/**
 * Class QROutputAbstract
 *
 * @filesource   QROutputAbstract.php
 * @created      09.12.2015
 * @package      Kekspay\Vendor\chillerlan\QRCode\Output
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2015 Smiley
 * @license      MIT
 *
 * Modified by __root__ on 14-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Kekspay\Vendor\chillerlan\QRCode\Output;

use Kekspay\Vendor\chillerlan\QRCode\QRCode;
use Kekspay\Vendor\chillerlan\QRCode\Data\QRMatrix;
use Kekspay\Vendor\chillerlan\Settings\SettingsContainerInterface;

use function call_user_func_array, dirname, file_put_contents, get_called_class, in_array, is_writable, sprintf;

/**
 * common output abstract
 */
abstract class QROutputAbstract implements QROutputInterface{

	/**
	 * the current size of the QR matrix
	 *
	 * @see \Kekspay\Vendor\chillerlan\QRCode\Data\QRMatrix::size()
	 */
	protected int $moduleCount;

	/**
	 * the current output mode
	 *
	 * @see \Kekspay\Vendor\chillerlan\QRCode\QROptions::$outputType
	 */
	protected string $outputMode;

	/**
	 * the default output mode of the current output module
	 */
	protected string $defaultMode;

	/**
	 * the current scaling for a QR pixel
	 *
	 * @see \Kekspay\Vendor\chillerlan\QRCode\QROptions::$scale
	 */
	protected int $scale;

	/**
	 * the side length of the QR image (modules * scale)
	 */
	protected int $length;

	/**
	 * an (optional) array of color values for the several QR matrix parts
	 */
	protected array $moduleValues;

	/**
	 * the (filled) data matrix object
	 */
	protected QRMatrix $matrix;

	/**
	 * @var \Kekspay\Vendor\chillerlan\Settings\SettingsContainerInterface|\Kekspay\Vendor\chillerlan\QRCode\QROptions
	 */
	protected SettingsContainerInterface $options;

	/**
	 * QROutputAbstract constructor.
	 */
	public function __construct(SettingsContainerInterface $options, QRMatrix $matrix){
		$this->options     = $options;
		$this->matrix      = $matrix;
		$this->moduleCount = $this->matrix->size();
		$this->scale       = $this->options->scale;
		$this->length      = $this->moduleCount * $this->scale;

		$class = get_called_class();

		if(isset(QRCode::OUTPUT_MODES[$class]) && in_array($this->options->outputType, QRCode::OUTPUT_MODES[$class])){
			$this->outputMode = $this->options->outputType;
		}

		$this->setModuleValues();
	}

	/**
	 * Sets the initial module values (clean-up & defaults)
	 */
	abstract protected function setModuleValues():void;

	/**
	 * saves the qr data to a file
	 *
	 * @see file_put_contents()
	 * @see \Kekspay\Vendor\chillerlan\QRCode\QROptions::cachefile
	 *
	 * @throws \Kekspay\Vendor\chillerlan\QRCode\Output\QRCodeOutputException
	 */
	protected function saveToFile(string $data, string $file):bool{

		if(!is_writable(dirname($file))){
			throw new QRCodeOutputException(sprintf('Could not write data to cache file: %s', $file));
		}

		return (bool)file_put_contents($file, $data);
	}

	/**
	 * @inheritDoc
	 */
	public function dump(string $file = null){
		$file ??= $this->options->cachefile;

		// call the built-in output method with the optional file path as parameter
		// to make the called method aware if a cache file was given
		$data = call_user_func_array([$this, $this->outputMode ?? $this->defaultMode], [$file]);

		if($file !== null){
			$this->saveToFile($data, $file);
		}

		return $data;
	}

}
