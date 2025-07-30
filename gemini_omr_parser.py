import sys
import json
import pathlib
import google.generativeai as genai
from PIL import Image

def main():
    """
    Takes an image, sends it to Gemini, and prints a CSV of detected marks
    by anchoring its search to the printed UUID for maximum accuracy.
    """
    # IMPORTANT: Replace with your API key. For production, use an environment variable.
    API_KEY = 'AIzaSyDOv7fsObK-PfbQ_W6x5pUW66qaAa2vmNE'
    
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file path provided."}))
        sys.exit(1)

    image_path = pathlib.Path(sys.argv[1])
    if not image_path.exists():
        print(json.dumps({"error": f"File not found at: {sys.argv[1]}"}))
        sys.exit(1)

    try:
        genai.configure(api_key=API_KEY)
        # Use a low temperature for maximum factuality and consistency
        generation_config = genai.types.GenerationConfig(temperature=0.0)
        model = genai.GenerativeModel('gemini-2.5-flash', generation_config=generation_config)
        image = Image.open(image_path)
    except Exception as e:
        print(json.dumps({"error": f"Failed to initialize AI model or open image: {str(e)}"}))
        sys.exit(1)

    prompt = """
You are a hyper-accurate Optical Mark and Character Recognition (OMR/OCR) system.
Your task is to analyze the provided image of a calling sheet.

**Analysis Protocol:**
For every row you find, follow these steps:
1.  **Find the Anchor:** Use OCR to read the "Unique id" string (e.g., `ID-XXXXXX-XXXX`). This is your anchor.
2.  **Find Slot:** In the "Slot" column of the anchored row, find the single digit that is written or circled.
3.  **Find Connectivity:** In the "Connectivity" column of the anchored row, find the marked circle ('Y' or 'N').
4.  **Find Disposition:** In the "Disposition" column of the anchored row, find the single marked circle and read the two-digit number next to it.

**Output Rules:**
1.  Your entire output MUST be in raw CSV format with a header.
2.  The header must be exactly: `unique_id,slot,connectivity_code,disposition_code`.
3.  If you find a Unique ID but no marks, output the ID with blank codes (e.g., `ID-...,,,`).
4.  Do not include any text, explanations, or markdown formatting.
"""

    try:
        response = model.generate_content([prompt, image])
        # Clean the response text just in case Gemini adds markdown
        cleaned_text = response.text.replace('```csv', '').replace('```', '').strip()
        print(cleaned_text)
    except Exception as e:
        print(json.dumps({"error": f"An error occurred with the Gemini API call: {str(e)}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()