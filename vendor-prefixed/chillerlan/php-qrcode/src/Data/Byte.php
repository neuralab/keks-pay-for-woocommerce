<?php
/**
 * Class Byte
 *
 * @filesource   Byte.php
 * @created      25.11.2015
 * @package      Kekspay\Vendor\chillerlan\QRCode\Data
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2015 Smiley
 * @license      MIT
 *
 * Modified by __root__ on 14-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Kekspay\Vendor\chillerlan\QRCode\Data;

use Kekspay\Vendor\chillerlan\QRCode\QRCode;

use function ord;

/**
 * Byte mode, ISO-8859-1 or UTF-8
 *
 * ISO/IEC 18004:2000 Section 8.3.4
 * ISO/IEC 18004:2000 Section 8.4.4
 */
final class Byte extends QRDataAbstract{

	protected int $datamode = QRCode::DATA_BYTE;

	protected array $lengthBits = [8, 16, 16];

	/**
	 * @inheritdoc
	 */
	protected function write(string $data):void{
		$i = 0;

		while($i < $this->strlen){
			$this->bitBuffer->put(ord($data[$i]), 8);
			$i++;
		}

	}

}
