import { BrowserCodeReader } from '@zxing/browser';
import { ChecksumException, DecodeHintType, FormatException, NotFoundException, QRCodeReader } from '@zxing/library';

export function createTicketQrReader() {
    const reader = new QRCodeReader();
    return new BrowserCodeReader({
        decode(bitmap, hints) {
            try {
                return reader.decode(bitmap, hints);
            } catch (error) {
                if (!(error instanceof NotFoundException || error instanceof ChecksumException || error instanceof FormatException)) throw error;
                // ZXing can mistake data modules for alignment marks on a clean,
                // front-facing QR. Retry direct module extraction; checksum and
                // error correction still validate the result before accepting it.
                try {
                    return reader.decode(bitmap, new Map([...hints, [DecodeHintType.PURE_BARCODE, true]]));
                } catch {
                    throw error;
                }
            }
        },
        reset() { reader.reset(); },
    });
}
