import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BarcodeFormat, BitMatrix, QRCodeWriter } from '@zxing/library';
import { createTicketQrReader } from '../../../resources/js/ticket-qr-reader.js';

const code = 'uM3AHOr4O9FK8OWt26lS4rCMvQGEB4ZUbMF07r6xjNbjhZLROJZvsCQNiM7Zvcbh';

for (const rotated of [false, true]) {
    test(`decodes a genuine ticket QR${rotated ? ' upside down' : ''}`, () => {
        const matrix = new QRCodeWriter().encode(code, BarcodeFormat.QR_CODE, 480, 480, new Map());
        if (rotated) matrix.rotate180();
        const result = createTicketQrReader().decodeBitmap({ getBlackMatrix: () => matrix });
        assert.equal(result.getText(), code);
    });
}

test('rejects an empty frame and a QR whose data modules have been erased', () => {
    const damaged = new QRCodeWriter().encode(code, BarcodeFormat.QR_CODE, 480, 480, new Map());
    for (let y = 150; y < 400; y++) {
        for (let x = 100; x < 400; x++) damaged.unset(x, y);
    }
    const reader = createTicketQrReader();
    for (const matrix of [new BitMatrix(480, 480), damaged]) {
        assert.throws(() => reader.decodeBitmap({ getBlackMatrix: () => matrix }));
    }
});
