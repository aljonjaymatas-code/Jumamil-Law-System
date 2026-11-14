import json
import sys

try:
    import pymupdf as fitz
except ImportError:
    fitz = None


MAX_WIDTH = 1600
MAX_HEIGHT = 2200
MAX_SCALE = 1.5


def main():
    if len(sys.argv) != 4:
        print(json.dumps({"error": "Invalid preview request."}))
        return 2
    if fitz is None:
        print(json.dumps({"error": "PyMuPDF is not installed."}))
        return 2

    source_path, raw_page_number, output_path = sys.argv[1:]
    try:
        page_number = int(raw_page_number)
        if page_number < 1:
            print(json.dumps({"error": "Page number must be positive."}))
            return 2

        document = fitz.open(source_path)
        try:
            page_count = document.page_count
            if page_number > page_count:
                print(json.dumps({"error": "Page number is outside this document."}))
                return 2

            page = document.load_page(page_number - 1)
            bounds = page.rect
            scale = min(
                MAX_SCALE,
                MAX_WIDTH / max(bounds.width, 1),
                MAX_HEIGHT / max(bounds.height, 1),
            )
            pixmap = page.get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
            pixmap.save(output_path)
        finally:
            document.close()
    except (OSError, RuntimeError, ValueError) as error:
        print(json.dumps({"error": str(error)}))
        return 1

    print(json.dumps({"page_count": page_count}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
