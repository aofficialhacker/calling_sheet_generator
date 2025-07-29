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
    Your task is to analyze the provided partial image of a calling sheet.

    **Primary Directive:** You MUST use the printed alphanumeric string in the "Unique ID" column as the anchor for each row. A Unique ID is a long alphanumeric string with hyphens, like this: `1234abcd-5678-efgh-9012-ijklmnopqrst`.

    **Analysis Protocol (Chain of Thought):**
    You must follow this protocol for every row you find:
    1.  **Find the Anchor:** Scan the document from top to bottom. Use OCR to read a "Unique ID" string. This string is your `unique_id`.
    2.  **Search within the Anchored Row:** Once anchored on a row by its Unique ID, perform all subsequent searches ONLY within that same horizontal band.
    3.  **Find Connectivity:** In the "Connectivity" column of that anchored row, identify which circle ('Y' or 'N') is marked. This is the `connectivity_code`.
    4.  **Find Disposition:** In the "Disposition" column of the *same* anchored row, find the single marked circle. The two-digit number next to that mark is the `disposition_code`.
    5.  **Repeat:** Move to the next Unique ID below and repeat the process.

    **Output Rules:**
    1.  Your entire output MUST be in raw CSV format with a header.
    2.  The header must be exactly: `unique_id,connectivity_code,disposition_code`.
    3.  The `unique_id` MUST be the full string you read from the "Unique ID" column.
    4.  If you find a Unique ID but no marks, output the ID with blank codes (e.g., `1234abcd-...,,`).
    5.  Do not include any text, explanations, or markdown formatting.
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