# Feature to add questions and answers to the database using AI

 - new admin menu point: generate questions
 - user can then enter a topic
 - backend will contact openai api using model gpt-5.4-mini
 - secret is stored in game_config.php
 - it will then contact the openai backend and get 5 questions and answers
 - the user can then select which of these sets he wants to add
 - try again button to send the same topics again
 - the prompt should state something like: that the user is a beginner (A1 level), spanish to german, maximum 3 word answers, maximum 30 characters per answer
 - AI generate alternative accepted answers (e.g. "el gato" → "die Katze" / "Katze")
 - question format: Spanish word/phrase as the question, German translation as the answer(s), matching existing questions.yaml format
 - show a loading spinner while waiting for OpenAI response
 - if no API key is configured in game_config.php, show an error message instead of the form
 - only admins can access this feature (admin-only menu item)
 - accepted questions are appended to the existing data/questions.yaml file
