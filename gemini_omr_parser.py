import sys
import json
import pathlib
import re
from google import genai
from google.genai import types
from PIL import Image
import cv2
import numpy as np
import os

def preprocess_image(input_path: pathlib.Path) -> pathlib.Path | None:
    try:
        image = cv2.imread(str(input_path))
        if image is None:
            return None
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        denoised = cv2.fastNlMeansDenoising(gray, None, h=10, templateWindowSize=7, searchWindowSize=21)
        binary_image = cv2.adaptiveThreshold(
            denoised, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
            cv2.THRESH_BINARY, 11, 2
        )
        output_path = input_path.parent / f"preprocessed_{input_path.name}"
        cv2.imwrite(str(output_path), binary_image)
        return output_path
    except Exception:
        return input_path

def main():
    # Preferred: use environment variables for your API key
    api_key = "AIzaSyDD1HTQWJNbrzFxwH5YWIHbnmcx9-FD_4s"
    if not api_key or api_key == 'YOUR_API_KEY':
        print(json.dumps({"error": "API key not set. Set GEMINI_API_KEY environment variable."}))
        sys.exit(1)

    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file path provided."}))
        sys.exit(1)

    image_path = pathlib.Path(sys.argv[1])
    if not image_path.exists():
        print(json.dumps({"error": f"File not found at: {sys.argv[1]}"}))
        sys.exit(1)

    preprocessed_path = preprocess_image(image_path)
    if not preprocessed_path or not preprocessed_path.exists():
        print(json.dumps({"error": "Image preprocessing failed."}))
        sys.exit(1)

    try:
        prompt = """
You are a hyper-accurate Optical Mark and Character Recognition (OMR/OCR) system.
Your task is to analyze the provided image of a calling sheet. The columns in the image are: Id, Slot, Connectivity, Disposition.

**Analysis Protocol:**
For every single row you can find on the sheet, follow these steps precisely:
1.  **Find the Anchor:** Use OCR to read the "Id" string. It is a long, unique alphanumeric value (e.g., LIV01B00100001). This is your anchor for the row. If you cannot read an Id, skip the row.
2.  **Find Slot:** In the "Slot" column for that same row, find the single digit that has been written or circled by a human. If nothing is marked or written in the slot area for that row, you MUST output a blank value for it.
3.  **Find Connectivity:** In the "Connectivity" column for that same row, find which of the two circles ('Y' or 'N') is marked. Output only the letter 'Y' or 'N'. If neither is marked, you MUST output a blank value.
4.  **Find Disposition:** In the "Disposition" column for that same row, find the single marked circle and read the two-digit number written right next to it. If no circle is marked in the disposition area for that row, you MUST output a blank value for the disposition code.

**Output Rules (Strict):**
1.  Your entire output MUST be in raw CSV format with a header row.
2.  The header must be exactly: `record_id,slot,connectivity_code,disposition_code`.
3.  You must output one CSV line for every Id you can read, even if no other marks are present for that row.
4.  Example for a row with an Id but no other marks: `LIV01B00100001,,,`
5.  Example of a full, valid response:
    ```csv
    record_id,slot,connectivity_code,disposition_code
    LIV01B00100001,1,Y,11
    LIV01B00100002,,,
    LIV01B00100003,5,N,22
    ```
6.  Do not include any text, explanations, or markdown formatting outside of the single `csv` block.
"""
        with Image.open(preprocessed_path) as image:
            client = genai.Client(api_key=api_key)
            # Supply both prompt and image as content parts, latest supports passing image byte data
            response = client.models.generate_content(
                model="gemini-2.5-flash",
                contents=[prompt, image],
                config=types.GenerateContentConfig(
                    thinking_config=types.ThinkingConfig(
                        thinking_budget=0  # disables "thinking"/reflection
                    )
                ),
            )
        text = response.text
        
        # Look for CSV content in markdown code blocks
        csv_match = re.search(r"```(?:csv)?\s*\n(.*?)\n```", text, re.DOTALL)
        if csv_match:
            cleaned_text = csv_match.group(1).strip()
        else:
            # If no code block found, use the entire text
            cleaned_text = text.strip()

        if cleaned_text and not cleaned_text.lower().startswith('record_id'):
            print(json.dumps({"error": f"AI response did not start with the expected CSV header 'record_id'. Response: {cleaned_text}"}))
            sys.exit(1)

        print(cleaned_text)

    except Exception as e:
        print(json.dumps({"error": f"An error occurred during the process: {str(e)}"}))
    finally:
        if preprocessed_path.exists():
            try:
                preprocessed_path.unlink()
            except OSError as e:
                print(json.dumps({"warning": f"Could not remove temp file: {e}"}), file=sys.stderr)

if __name__ == "__main__":
    main()
