function formatFileSize(size) {
	const units = [
		[1024**4, 'TiB'],
		[1024**3, 'GiB'],
		[1024**2, 'MiB'],
		[1024, 'KiB']
	];

	for (const [unit, val] of units) {
		if (size < unit * 1.1) continue;
		return (size / unit).toFixed(2) + ' ' + val;
	}
	return size + ' Bytes';
}

export {
	formatFileSize
}