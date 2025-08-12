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
    """Preprocess image for better OCR results"""
    try:
        image = cv2.imread(str(input_path))
        if image is None:
            return None
        
        # Convert to grayscale
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        
        # Denoise the image
        denoised = cv2.fastNlMeansDenoising(gray, None, h=10, templateWindowSize=7, searchWindowSize=21)
        
        # Apply adaptive threshold for better text recognition
        binary_image = cv2.adaptiveThreshold(
            denoised, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
            cv2.THRESH_BINARY, 11, 2
        )
        
        # Save preprocessed image
        output_path = input_path.parent / f"preprocessed_{input_path.name}"
        cv2.imwrite(str(output_path), binary_image)
        return output_path
    except Exception:
        return input_path

def main():
    # API key (should ideally be from environment variable)
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

    # Preprocess the image for better OCR
    preprocessed_path = preprocess_image(image_path)
    if not preprocessed_path or not preprocessed_path.exists():
        print(json.dumps({"error": "Image preprocessing failed."}))
        sys.exit(1)

    try:
        prompt = """
You are an advanced Optical Character Recognition (OCR) system specialized in analyzing Excel spreadsheet images.
Your task is to analyze the provided image of an Excel spreadsheet and extract the data with proper column headers.

**Analysis Protocol:**
1. **Identify Headers:** Look for the first row that contains column headers. Common headers might include:
   - Customer/Contact information: name, title, mobile_no, phone, email
   - Personal details: age, dob, gender, address, city, state, country, pincode
   - Policy/Insurance information: policy_number, plan, premium, sum_insured, expiry
   - Financial information: pan, income
   - Other relevant business data columns

2. **Classify Headers:** Based on the data values in each column, classify headers appropriately:
   - If you see phone numbers → "mobile_no"
   - If you see names → "name" 
   - If you see policy numbers → "policy_number"
   - If you see dates → "dob" or "expiry" (based on context)
   - If you see addresses → "address"
   - If you see monetary values → "premium" or "sum_insured"
   - If you see PAN format (10 alphanumeric) → "pan"
   - And so on...

3. **Extract Data:** For every row of data (excluding headers), extract the values in the correct columns.

**Output Rules (Strict):**
1. Your entire output MUST be in raw CSV format with a header row.
2. Use standardized column names from this list when possible:
   - id, name, title, mobile_no, phone, email, age, dob, gender
   - address, city, state, country, pincode, policy_number, pan
   - plan, premium, sum_insured, expiry, connectivity, disposition, slot
3. If you encounter columns that don't match the standard names, use descriptive names based on the data.
4. Include ALL rows of data you can read from the spreadsheet.
5. If a cell is empty or unreadable, leave it blank in the CSV.
6. Example output format:
   ```csv
   name,mobile_no,policy_number,premium,city
   John Doe,9876543210,POL123456,50000,Mumbai
   Jane Smith,9876543211,POL123457,75000,Delhi
   ```
7. Do not include any text, explanations, or markdown formatting outside of the single `csv` block.
"""

        with Image.open(preprocessed_path) as image:
            client = genai.Client(api_key=api_key)
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

        # Basic validation - should have at least a header row
        if not cleaned_text or len(cleaned_text.split('\n')) < 1:
            print(json.dumps({"error": f"AI response did not contain valid CSV data. Response: {cleaned_text}"}))
            sys.exit(1)

        print(cleaned_text)

    except Exception as e:
        print(json.dumps({"error": f"An error occurred during the process: {str(e)}"}))
    finally:
        # Clean up preprocessed image
        if preprocessed_path.exists() and preprocessed_path != image_path:
            try:
                preprocessed_path.unlink()
            except OSError as e:
                print(json.dumps({"warning": f"Could not remove temp file: {e}"}), file=sys.stderr)

if __name__ == "__main__":
    main()