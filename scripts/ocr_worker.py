import json
import os
import re
import sys
import zipfile
from datetime import datetime
from xml.etree import ElementTree

try:
    import pymupdf as fitz
except ImportError:  # pragma: no cover
    fitz = None

try:
    import easyocr
except ImportError:  # pragma: no cover
    easyocr = None

try:
    import numpy as np
except ImportError:  # pragma: no cover
    np = None


def read_txt_file(path):
    with open(path, 'r', encoding='utf-8-sig', errors='replace') as fh:
        return fh.read()


def read_docx_file(path):
    with zipfile.ZipFile(path) as zf:
        data = zf.read('word/document.xml')
    root = ElementTree.fromstring(data)
    ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    paragraphs = []
    for para in root.findall('.//w:p', ns):
        text = ''.join(node.text or '' for node in para.findall('.//w:t', ns))
        if text.strip():
            paragraphs.append(text.strip())
    return '\n'.join(paragraphs)


def pdf_to_text(path):
    if fitz is None:
        return ''

    doc = fitz.open(path)
    pages = []
    reader = None

    try:
        for page in doc:
            page_text = page.get_text('text')
            if page_text and page_text.strip():
                pages.append(page_text.strip())
                continue
            if easyocr is None or np is None:
                continue
            if reader is None:
                reader = easyocr.Reader(['en'], gpu=False)
            pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
            image = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.h, pix.w, pix.n)
            result = reader.readtext(image, detail=0, paragraph=True)
            if result:
                pages.append('\n'.join(result))
    finally:
        doc.close()

    return '\n\n'.join(pages)


def normalize_value(value):
    if value is None:
        return None
    text = str(value).strip()
    return text or None


def extract_metadata(text):
    metadata = {
        'document_number': None,
        'page_number': None,
        'book_number': None,
        'series_year': None,
        'date_notarized': None,
        'client_name': None,
    }

    if not text:
        return metadata

    patterns = [
        r'(?:Document\s*(?:No|Number)|Doc\s*(?:No|Number)|Doc\.?\s*#|Document\s*#)\s*[:#-]?\s*([A-Za-z0-9\-/]+)',
        r'(?:No\.?|Number)\s*[:#-]?\s*([A-Za-z0-9\-/]+)',
    ]
    for pattern in patterns:
        match = re.search(pattern, text, flags=re.IGNORECASE)
        if match:
            metadata['document_number'] = normalize_value(match.group(1))
            break

    page_match = re.search(r'(?:Page|Pages)\s*(?:No\.?|Number)?\s*[:#-]?\s*([0-9]+)', text, flags=re.IGNORECASE)
    if page_match:
        metadata['page_number'] = normalize_value(page_match.group(1))

    book_match = re.search(r'(?:Book)\s*(?:No\.?|Number)?\s*[:#-]?\s*([A-Za-z0-9\-/]+)', text, flags=re.IGNORECASE)
    if book_match:
        metadata['book_number'] = normalize_value(book_match.group(1))

    series_match = re.search(r'(?:Series)\s*(?:Year|Yr)?\s*[:#-]?\s*(\d{4})', text, flags=re.IGNORECASE)
    if series_match:
        metadata['series_year'] = normalize_value(series_match.group(1))

    date_patterns = [
        r'(?:Date\s*(?:Notarized|Executed)|Notarized\s*on|Date\s*[:\-])\s*(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})',
        r'(?:Date\s*(?:Notarized|Executed)|Notarized\s*on|Date\s*[:\-])\s*(\d{1,2}\s+[A-Za-z]+\s+\d{4})',
        r'(?:Date\s*(?:Notarized|Executed)|Notarized\s*on|Date\s*[:\-])\s*([A-Za-z]+\s+\d{1,2},?\s+\d{4})',
        r'(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})',
        r'(\d{1,2}\s+[A-Za-z]+\s+\d{4})',
        r'([A-Za-z]+\s+\d{1,2},?\s+\d{4})',
    ]
    for pattern in date_patterns:
        match = re.search(pattern, text, flags=re.IGNORECASE)
        if match:
            raw_date = match.group(1) if match.lastindex else match.group(0)
            for date_format in (
                '%m/%d/%Y',
                '%m-%d-%Y',
                '%d/%m/%Y',
                '%d-%m-%Y',
                '%d %B %Y',
                '%d %b %Y',
                '%B %d, %Y',
                '%B %d %Y',
                '%b %d, %Y',
                '%b %d %Y',
            ):
                try:
                    metadata['date_notarized'] = datetime.strptime(raw_date, date_format).strftime('%Y-%m-%d')
                    break
                except ValueError:
                    pass
            if metadata['date_notarized']:
                break

    client_match = re.search(r'(?:Client\s*(?:Name|Full\s*Name)|Name\s*of\s*Client)\s*[:\-]?\s*([^\r\n]+)', text, flags=re.IGNORECASE)
    if client_match:
        metadata['client_name'] = normalize_value(client_match.group(1))

    return metadata


def load_text_for_file(path):
    ext = os.path.splitext(path)[1].lower()

    if ext == '.txt':
        return read_txt_file(path)
    if ext == '.docx':
        return read_docx_file(path)
    if ext == '.pdf':
        return pdf_to_text(path)
    if ext == '.doc':
        raise ValueError('Legacy .doc files are not supported for text extraction. Save as .docx or PDF.')
    raise ValueError('Unsupported file type for text extraction.')


def main():
    if len(sys.argv) < 2:
        print(json.dumps({'status': 'failed', 'message': 'No file path supplied.'}))
        return 1

    path = sys.argv[1]
    if not os.path.isfile(path):
        print(json.dumps({'status': 'failed', 'message': 'File not found.'}))
        return 1

    try:
        text = load_text_for_file(path)
    except (OSError, ValueError, zipfile.BadZipFile, ElementTree.ParseError) as error:
        print(json.dumps({'status': 'failed', 'message': str(error)}))
        return 1
    metadata = extract_metadata(text)
    has_text = bool(text.strip())
    payload = {
        'status': 'completed' if has_text else 'failed',
        'message': 'OCR completed successfully.' if has_text else 'No readable text could be extracted from the file.',
        'text': text[:20000],
        **metadata,
    }
    print(json.dumps(payload))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
