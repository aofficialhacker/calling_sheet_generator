import sys
import json
import pathlib
import google.generativeai as genai
from PIL import Image

def main():
    """
    This script takes an image file path as a command-line argument,
    sends it to the Gemini Pro Vision API for OMR, and prints the resulting
    CSV text to standard output.
    """
    # --- CONFIGURATION ---
    # IMPORTANT: For production, store this securely (e.g., as an environment variable).
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
        model = genai.GenerativeModel('gemini-2.5-flash')
        image = Image.open(image_path)
    except Exception as e:
        print(json.dumps({"error": f"Failed to initialize AI model or open image: {str(e)}"}))
        sys.exit(1)

    # ########## THE FIX IS HERE: A MORE DETAILED PROMPT ##########
    # This new prompt instructs the AI on exactly how to reason about the image.
    prompt = """
    You are an expert data entry assistant specializing in high-accuracy Optical Mark Recognition (OMR).
    The user has provided an image of a calling sheet with multiple data rows.
    Your task is to analyze each row and identify which options have been marked.

    Here are the explicit rules and the step-by-step process you must follow:

    1.  **Output Format:** Your entire output MUST be in CSV format with a header row. The header must be exactly: `row_index,connectivity_code,disposition_code`

    2.  **Row-by-Row Analysis:** Process each numbered data row in the image, from top to bottom. The `row_index` in your CSV should correspond to the number printed at the start of the row in the image.

    3.  **Connectivity Logic:** For the 'Connectivity' column, if the circle prior to 'Y' is marked, the `connectivity_code` is 'Y'. If the circle prior to 'N' is marked, the `connectivity_code` is 'N'.

    4.  **Disposition Logic (Chain-of-Thought):** This is the most critical part. For each data row, perform the following mental steps to find the disposition:
        *   **Step 4a:** Locate the 'Disposition' area for that specific row. This area contains a long series of circles with two-digit numbers next to them.
        *   **Step 4b:** Scan across that series of circles to find the single circle that has been filled in, ticked, or marked.
        *   **Step 4c:** Once you have located the *exact* marked circle, identify the two-digit number printed immediately next to it.
        *   **Step 4d:** That two-digit number is the `disposition_code` for your CSV row. The possible codes are 11, 12, 13, 14, 15, 16, 17, 21, 22, 23, 24, 25, 26.

    5.  **Handling Blanks:** If a row is completely empty, or you cannot confidently determine a mark for a specific field (Connectivity or Disposition), leave that corresponding field blank in your CSV output. Do not guess.

    6.  **Final Output Command:** Only output the raw CSV data. Do not include any other text, explanations, or markdown formatting like ```csv.
    """
    # ########## END OF THE FIX ##########

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