<?php

function uojRand($l, $r) {
	return mt_rand($l, $r);
}

// Session tokens, remembered logins and the secrets of the single sign-on are made of these
// strings, so they must not be predictable: mt_rand() is.
function uojRandString($len, $charset = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {
	$n_chars = strlen($charset);
	$str = '';
	for ($i = 0; $i < $len; $i++) {
		$str .= $charset[random_int(0, $n_chars - 1)];
	}
	return $str;
}

function uojRandAvailableFileName($dir) {
	do {
		$fileName = $dir . uojRandString(20);
	} while (file_exists(UOJContext::storagePath().$fileName));
	return $fileName;
}

function uojRandAvailableTmpFileName() {
	return uojRandAvailableFileName('/tmp/');
}

function uojRandAvailableSubmissionFileName() {
	$num = uojRand(1, 10000);
	if (!file_exists(UOJContext::storagePath()."/submission/$num")) {
		system("mkdir ".UOJContext::storagePath()."/submission/$num");
	}
	return uojRandAvailableFileName("/submission/$num/");
}

function uojRandAvailablePasteFileName() {
	return uojRandAvailableFileName('/paste/');
}
